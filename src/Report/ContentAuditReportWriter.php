<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use MergeMultisite\ContentAudit\ContentAuditRow;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes `site-audit.php` results as CSV (opens cleanly in Excel or
 * pastes straight into Google Sheets), JSON, and a plain-text summary
 * tally (e.g. "N pages use Contact Form 7, M pages use WPForms").
 *
 * @package MergeMultisite
 */
final class ContentAuditReportWriter {

	/**
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories   Every detector category, in the order columns should appear.
	 * @param array             $pluginUsage       Optional PluginUsageRollup::build() result, rendered as
	 *                                             the first summary section.
	 * @param string            $spreadsheetFormat 'xlsx', 'csv', or 'both' (config spreadsheet_format).
	 *                                             'xlsx' falls back to CSV when ext-zip is missing, so
	 *                                             a spreadsheet always lands.
	 *
	 * @return array{csv: string|null, json: string, summary: string, xlsx: string|null}
	 */
	public function write( array $rows, array $categories, string $outputDirectory, string $baseName, array $pluginUsage = array(), string $spreadsheetFormat = 'both' ): array {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$jsonPath    = $outputDirectory . '/' . $baseName . '.json';
		$summaryPath = $outputDirectory . '/' . $baseName . '-summary.md';

		file_put_contents( $jsonPath, $this->toJson( $rows ) );
		file_put_contents( $summaryPath, $this->toSummary( $rows, $categories, $pluginUsage ) );

		// The .xlsx needs ext-zip (xlsx is a zip of XML parts); when it
		// is missing and xlsx was requested, fall back to CSV rather
		// than produce no spreadsheet at all.
		$csvPath  = null;
		$xlsxPath = null;
		$wantXlsx = $spreadsheetFormat !== 'csv' && extension_loaded( 'zip' );
		if ( $wantXlsx ) {
			$xlsxPath = $outputDirectory . '/' . $baseName . '.xlsx';
			$this->toXlsx( $rows, $categories, $xlsxPath );
		}
		if ( $spreadsheetFormat === 'csv' || $spreadsheetFormat === 'both' || ( $spreadsheetFormat === 'xlsx' && $xlsxPath === null ) ) {
			$csvPath = $outputDirectory . '/' . $baseName . '.csv';
			file_put_contents( $csvPath, $this->toCsv( $rows, $categories ) );
		}

		return array(
			'csv'     => $csvPath,
			'json'    => $jsonPath,
			'summary' => $summaryPath,
			'xlsx'    => $xlsxPath,
		);
	}

	/**
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories
	 */
	public function toCsv( array $rows, array $categories ): string {
		$handle = fopen( 'php://temp', 'w+' );

		$header = array( 'blog_id', 'domain', 'post_id', 'post_type', 'post_status', 'post_title', 'slug', 'original_url', 'template', 'template_status', ...$categories );
		fputcsv( $handle, $header, ',', '"', '\\' );

		foreach ( $rows as $row ) {
			$rowData = $row->toRow();
			fputcsv( $handle, array_map( static fn ( string $key ): string => $rowData[ $key ] ?? '', $header ), ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return $csv === false ? '' : $csv;
	}

	/**
	 * @param ContentAuditRow[] $rows
	 */
	public function toJson( array $rows ): string {
		$payload = array_map( static fn ( ContentAuditRow $row ): array => $row->toRow(), $rows );

		return (string) json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories
	 * @param array             $pluginUsage PluginUsageRollup::build() result, or empty.
	 */
	public function toSummary( array $rows, array $categories, array $pluginUsage = array() ): string {
		$lines = array( '# Content/Plugin Usage Summary', '' );

		if ( $pluginUsage !== array() ) {
			$lines = array( ...$lines, ...$this->pluginUsageSection( $pluginUsage ) );
		}

		$lines = array( ...$lines, ...$this->templateStatusSection( $rows ) );

		foreach ( $categories as $category ) {
			// label => ['count' => int, 'sites' => blog_id set]
			$tally = array();

			foreach ( $rows as $row ) {
				foreach ( $row->categoryFindings[ $category ] ?? array() as $label ) {
					$tally[ $label ]['count'] = ( $tally[ $label ]['count'] ?? 0 ) + 1;
					$tally[ $label ]['sites'][ $row->blogId ] = true;
				}
			}

			if ( $tally === array() ) {
				continue;
			}

			ksort( $tally, SORT_NATURAL | SORT_FLAG_CASE );

			$lines[] = sprintf( '## %s', $category );
			$lines[] = '';
			foreach ( $tally as $label => $data ) {
				$sites = array_map( 'intval', array_keys( $data['sites'] ) );
				sort( $sites );
				$lines[] = sprintf( '- %s: %d page(s) (sites: %s)', $label, $data['count'], implode( ', ', $sites ) );
			}
			$lines[] = '';
		}

		return implode( PHP_EOL, $lines ) . PHP_EOL;
	}

	/**
	 * Tally of the per-page template_status column: how many pages
	 * resolve to a customized/stale/missing template and therefore need
	 * rebuilding or evaluation in the new theme. Stale-theme
	 * customizations (rows that would render again if retagged to the
	 * active stylesheet) are listed individually -- they are the
	 * "rename-and-migrate" candidates.
	 *
	 * @param ContentAuditRow[] $rows
	 *
	 * @return string[]
	 */
	private function templateStatusSection( array $rows ): array {
		$byStatus = array();
		$staleTemplates = array();
		foreach ( $rows as $row ) {
			if ( $row->templateStatus === '' ) {
				continue;
			}
			$byStatus[ $row->templateStatus ][ $row->blogId ] = ( $byStatus[ $row->templateStatus ][ $row->blogId ] ?? 0 ) + 1;
			if ( $row->templateStatus === 'stale-customization' || $row->templateStatus === 'stale-theme-template' ) {
				$staleTemplates[ $row->template ][ $row->blogId ] = true;
			}
		}

		if ( $byStatus === array() ) {
			return array();
		}

		ksort( $byStatus );
		$lines = array( '## Page templates needing work', '' );
		foreach ( $byStatus as $status => $siteCounts ) {
			$lines[] = sprintf(
				'- %s: %d page(s) (sites: %s)',
				$status,
				array_sum( $siteCounts ),
				implode( ', ', array_map( 'intval', array_keys( $siteCounts ) ) )
			);
		}
		$lines[] = '';

		if ( $staleTemplates !== array() ) {
			ksort( $staleTemplates, SORT_NATURAL | SORT_FLAG_CASE );
			$lines[] = 'Inactive-theme templates pages still resolve to (retag the wp_template row to the active stylesheet to restore them):';
			$lines[] = '';
			foreach ( $staleTemplates as $template => $siteIds ) {
				$lines[] = sprintf( '- %s -- sites: %s', $template, implode( ', ', array_map( 'intval', array_keys( $siteIds ) ) ) );
			}
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * @param array{used: array, not_installed: array, not_detected: array, conflicts?: array} $pluginUsage
	 *
	 * @return string[]
	 */
	private function pluginUsageSection( array $pluginUsage ): array {
		$lines = array( '## Plugin usage', '' );

		$lines[] = 'Installed plugins detected in page content:';
		$lines[] = '';
		foreach ( $pluginUsage['used'] as $name => $entity ) {
			$lines[] = sprintf( '- %s (%s) -- sites: %s', $name, $entity['slug'], implode( ', ', $entity['sites'] ) );
			$lines = array( ...$lines, ...$this->signalLines( $entity['signals'] ) );
		}
		$lines[] = '';

		$lines[] = 'Content signals with NO matching installed plugin (removed-plugin leftovers, theme features, or unmapped labels):';
		$lines[] = '';
		foreach ( $pluginUsage['not_installed'] as $name => $entity ) {
			$lines[] = sprintf( '- %s -- sites: %s', $name, implode( ', ', $entity['sites'] ) );
			$lines = array( ...$lines, ...$this->signalLines( $entity['signals'] ) );
		}
		$lines[] = '';

		// Split "never detected" by whether the plugin holds data:
		// a plugin with rows/options/tables (Redirection's logs,
		// Accessibility Checker's scan results) has something worth
		// deciding about; a plugin with neither content markers nor
		// data is the safest removal candidate.
		$withData = array();
		$noData   = array();
		foreach ( $pluginUsage['not_detected'] as $slug => $info ) {
			if ( $info['has_data'] ?? false ) {
				$withData[ $slug ] = $info;
			} else {
				$noData[ $slug ] = $info;
			}
		}

		$lines[] = 'Installed plugins NEVER detected in content, WITH saved data'
			. ' (no page markup, but they own rows/options/tables -- decide'
			. ' whether that data migrates before removing):';
		$lines[] = '';
		foreach ( $withData as $slug => $info ) {
			$lines[] = sprintf(
				'- %s -- %s%s',
				$slug,
				$info['sites'] === array() ? 'not active on any scanned site' : 'active on sites: ' . implode( ', ', $info['sites'] ),
				$info['footprint'] === null ? '' : '; footprint: ' . $info['footprint']
			);
		}
		$lines[] = '';

		$lines[] = 'Installed plugins NEVER detected in content, NO data found'
			. ' (safest removal candidates -- spam/security/performance plugins'
			. ' legitimately leave no markers, so verify function before removing):';
		$lines[] = '';
		foreach ( $noData as $slug => $info ) {
			$lines[] = sprintf(
				'- %s -- %s',
				$slug,
				$info['sites'] === array() ? 'not active on any scanned site' : 'active on sites: ' . implode( ', ', $info['sites'] )
			);
		}
		$lines[] = '';

		if ( ( $pluginUsage['conflicts'] ?? array() ) !== array() ) {
			$lines[] = 'Plugin role conflicts -- two+ plugins in the same role are active.'
				. ' Different plugins per site is a consolidation decision;'
				. ' co-active on the SAME site is a real conflict (e.g. two SMTP'
				. ' plugins both override wp_mail()). The role list is a curated'
				. ' set of common families, not exhaustive -- a niche plugin'
				. ' outside it just won\'t appear here:';
			$lines[] = '';
			foreach ( $pluginUsage['conflicts'] as $family => $info ) {
				$lines[] = sprintf( '- %s:', $family );
				foreach ( $info['slugs'] as $slug => $sites ) {
					$lines[] = sprintf( '    %s -- active on sites: %s', $slug, implode( ', ', $sites ) );
				}
				if ( $info['overlap'] !== array() ) {
					$lines[] = sprintf( '    CONFLICT: co-active on site(s): %s', implode( ', ', $info['overlap'] ) );
				}
			}
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Renders an entity's signals as nested bullets grouped by
	 * category. Block namespaces with more than a handful of types
	 * collapse to "ns/* (N types)" instead of listing dozens of names
	 * (SureCart alone ships ~50 blocks).
	 *
	 * @param string[] $signals "category|label" entries.
	 *
	 * @return string[]
	 */
	private function signalLines( array $signals ): array {
		$byCategory = array();
		foreach ( $signals as $signal ) {
			$parts = explode( '|', $signal, 2 );
			$byCategory[ $parts[0] ][] = $parts[1];
		}
		ksort( $byCategory );

		$lines = array();
		foreach ( $byCategory as $category => $labels ) {
			sort( $labels );
			if ( $category === 'blocks' ) {
				$byNamespace = array();
				foreach ( $labels as $label ) {
					$namespace = str_contains( $label, '/' ) ? (string) strtok( $label, '/' ) : $label;
					$byNamespace[ $namespace ][] = $label;
				}
				$parts = array();
				foreach ( $byNamespace as $namespace => $namespaceLabels ) {
					$parts[] = count( $namespaceLabels ) > 4
						? sprintf( '%s/* (%d types)', $namespace, count( $namespaceLabels ) )
						: implode( ', ', $namespaceLabels );
				}
				$lines[] = '  - blocks: ' . implode( ', ', $parts );
			} else {
				$lines[] = sprintf(
					'  - %s: %s%s',
					$category,
					implode( ', ', array_slice( $labels, 0, 8 ) ),
					count( $labels ) > 8 ? sprintf( ' (+%d more)', count( $labels ) - 8 ) : ''
				);
			}
		}

		return $lines;
	}

	/**
	 * Writes the same rows as a real .xlsx workbook: wrapped text,
	 * explicit column widths (capped around 5"), a bold header row,
	 * an autofilter, and the header row + the blog_id and
	 * original_url columns frozen so they stay on screen while
	 * scrolling right/down through the detector columns.
	 *
	 * The blog_id and original_url columns come first specifically so
	 * they can be the frozen columns -- the identity of a row stays
	 * visible no matter how far right you scroll.
	 *
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories
	 */
	public function toXlsx( array $rows, array $categories, string $path ): void {
		$columns = array(
			'blog_id',
			'original_url',
			'post_id',
			'post_type',
			'post_title',
			'slug',
			'domain',
			'post_status',
			'template',
			'template_status',
			...$categories,
		);

		// PhpSpreadsheet widths are roughly character counts; ~50
		// characters is about 5" at the default font.
		$widths = array(
			'blog_id'         => 9,
			'original_url'    => 50,
			'post_id'         => 10,
			'post_type'       => 14,
			'post_title'      => 45,
			'slug'            => 30,
			'domain'          => 25,
			'post_status'     => 10,
			'template'        => 30,
			'template_status' => 24,
		);

		$spreadsheet = new Spreadsheet();
		// XLSX cells hold one font name, not a CSS fallback stack, so pick
		// the single name that resolves to a mono face on all three OSes:
		// Office ships Consolas on Windows AND macOS, and fontconfig maps
		// it to DejaVu Sans Mono on Linux. (Cascadia Mono, Menlo and
		// ui-monospace all fall back to PROPORTIONAL fonts on Linux --
		// worse than the Calibri default they were meant to replace.)
		$spreadsheet->getDefaultStyle()->getFont()->setName( 'Consolas' );
		$sheet       = $spreadsheet->getActiveSheet();
		$sheet->setTitle( 'site-audit' );

		foreach ( $columns as $index => $key ) {
			$letter = Coordinate::stringFromColumnIndex( $index + 1 );
			$sheet->setCellValue( $letter . '1', $key );
			$sheet->getColumnDimension( $letter )->setWidth( $widths[ $key ] ?? 40 );
		}

		foreach ( $rows as $rowIndex => $row ) {
			$rowData = $row->toRow();
			foreach ( $columns as $index => $key ) {
				$letter = Coordinate::stringFromColumnIndex( $index + 1 );
				$sheet->setCellValue( $letter . ( $rowIndex + 2 ), $rowData[ $key ] ?? '' );
			}
		}

		$lastColumn  = Coordinate::stringFromColumnIndex( count( $columns ) );
		$lastRow     = count( $rows ) + 1;
		$wholeRange  = 'A1:' . $lastColumn . $lastRow;

		$sheet->getStyle( 'A1:' . $lastColumn . '1' )->getFont()->setBold( true );
		$sheet->getStyle( $wholeRange )->getAlignment()
			->setWrapText( true )
			->setVertical( Alignment::VERTICAL_TOP );
		$sheet->freezePane( 'C2' );
		$sheet->setAutoFilter( $wholeRange );

		( new Xlsx( $spreadsheet ) )->save( $path );
		$spreadsheet->disconnectWorksheets();
	}
}
