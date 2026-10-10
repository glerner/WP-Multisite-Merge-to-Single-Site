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
	 *                                             the first summary section and used to resolve block
	 *                                             owners on the block-inventory worksheet tab.
	 * @param string            $spreadsheetFormat 'xlsx', 'csv', or 'both' (config spreadsheet_format).
	 *                                             'xlsx' falls back to CSV when ext-zip is missing, so
	 *                                             a spreadsheet always lands.
	 * @param array             $needsReviewDetails Optional NeedsReviewReportWriter input ({row, post}
	 *                                             pairs); rendered as the "needs-review" worksheet tab.
	 * @param string            $destinationUrl     Destination site URL, for the tab's URL guess column.
	 *
	 * @return array{csv: string|null, json: string, summary: string, xlsx: string|null}
	 */
	public function write( array $rows, array $categories, string $outputDirectory, string $baseName, array $pluginUsage = array(), string $spreadsheetFormat = 'both', array $needsReviewDetails = array(), string $destinationUrl = '' ): array {
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
			$this->toXlsx( $rows, $categories, $xlsxPath, $pluginUsage, $needsReviewDetails, $destinationUrl );
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
		$lines = array( ...$lines, ...$this->futurePostsSection( $rows ) );

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

		$lines = array( ...$lines, ...$this->customCssSection( $rows ) );

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
	 * Posts with post_status=future: the publish_future_post cron
	 * event lives in the 'cron' option and does not travel with the
	 * posts table, so migrated future posts never publish unless
	 * re-scheduled. bin/schedule-future-posts.sh generates an editable
	 * bash file of wp-cli commands (review/delete lines before running);
	 * doing nothing leaves them permanently unpublished (a soft-draft).
	 *
	 * @param ContentAuditRow[] $rows
	 *
	 * @return string[]
	 */
	private function futurePostsSection( array $rows ): array {
		$bySite = array();
		foreach ( $rows as $row ) {
			if ( $row->postStatus === 'future' ) {
				$bySite[ $row->blogId ] = ( $bySite[ $row->blogId ] ?? 0 ) + 1;
			}
		}
		if ( $bySite === array() ) {
			return array();
		}

		ksort( $bySite );
		$lines = array( '## Scheduled posts (post_status=future)', '' );
		foreach ( $bySite as $blogId => $count ) {
			$lines[] = sprintf( '- Site %d: %d post(s)', $blogId, $count );
		}
		$lines[] = '';
		$lines[] = 'Cron events do not travel with the posts table: to keep these publishing on schedule after migration, run `bin/schedule-future-posts.sh` against the destination -- it writes an editable `var/reports/schedule-future-posts-*.sh` you review before running (posts whose date already passed publish on the next cron run). To leave them unpublished instead, do nothing.';
		$lines[] = '';

		return $lines;
	}

	/**
	 * The Customizer's "Additional CSS" is stored as `custom_css`
	 * posts (post_name/post_title = the theme stylesheet it belongs
	 * to). The CSS migrates with the posts table but is keyed to the
	 * old theme, so it is quoted here verbatim for review -- anything
	 * still wanted can be pasted into the merged theme's style.css or
	 * the destination Customizer.
	 *
	 * @param ContentAuditRow[] $rows
	 *
	 * @return string[]
	 */
	private function customCssSection( array $rows ): array {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( $row->postType !== 'custom_css' || trim( $row->content ) === '' ) {
				continue;
			}
			if ( $lines === array() ) {
				$lines[] = '## Customizer Additional CSS';
				$lines[] = '';
				$lines[] = 'CSS stored in `custom_css` posts (the Customizer\'s "Additional CSS" field), quoted verbatim. Copy anything still wanted into the merged theme\'s style.css or the destination Customizer.';
				$lines[] = '';
			}
			$lines[] = sprintf( '### Site %d — %s (`%s`)', $row->blogId, $row->domain, $row->slug );
			$lines[] = '';
			$lines[] = '```css';
			$lines[] = rtrim( $row->content );
			$lines[] = '```';
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
	 * Build the rows for the `block-inventory` worksheet: one row per
	 * distinct (block, page) pair, so filtering the "Used On URL"
	 * column shows every block on a given page and filtering the block
	 * column shows every page (URL) that uses a given block. The owning plugin
	 * is resolved from PluginUsageRollup::build() output, whose
	 * signal_map comes from config/plugin-roles.php; "Occurrences" is
	 * the total number of pages using the block (repeated per row for
	 * at-a-glance totals). Sorted by block name, then URL.
	 *
	 * Blocks with no recorded owner (e.g. an unmapped namespace, or no
	 * pluginUsage passed in) get empty owner columns rather than being
	 * dropped -- the inventory is a complete block census, and a blank
	 * owner is itself a signal to map in plugin-roles.php.
	 *
	 * @param ContentAuditRow[] $rows
	 * @param array             $pluginUsage PluginUsageRollup::build() result, or empty.
	 *
	 * @return array<int, array{block: string, plugin: string, plugin_slug: string, occurrences: int, url: string}>
	 */
	public function blockInventory( array $rows, array $pluginUsage = array() ): array {
		// Block label ("uagb/forms") => owning plugin name + slug, from
		// the rollup's "blocks|label" signals on its used/not_installed
		// entities. Both sections carry resolved display names; only
		// 'used' has an installed slug.
		$ownerByBlock = array();
		foreach ( array( 'used', 'not_installed' ) as $section ) {
			foreach ( $pluginUsage[ $section ] ?? array() as $name => $entity ) {
				foreach ( $entity['signals'] ?? array() as $signal ) {
					if ( str_starts_with( (string) $signal, 'blocks|' ) ) {
						$ownerByBlock[ substr( (string) $signal, 7 ) ] = array(
							'name' => (string) $name,
							'slug' => isset( $entity['slug'] ) ? (string) $entity['slug'] : '',
						);
					}
				}
			}
		}

		$byBlock = array();
		foreach ( $rows as $row ) {
			$url = $row->toRow()['original_url'];
			foreach ( $row->categoryFindings['blocks'] ?? array() as $label ) {
				$label = (string) $label;
				$byBlock[ $label ]['occurrences'] = ( $byBlock[ $label ]['occurrences'] ?? 0 ) + 1;
				$byBlock[ $label ]['urls'][ $url ] = true;
				$byBlock[ $label ]['owner'] = $ownerByBlock[ $label ] ?? array(
					'name' => '',
					'slug' => '',
				);
			}
		}

		ksort( $byBlock, SORT_NATURAL | SORT_FLAG_CASE );

		$inventory = array();
		foreach ( $byBlock as $block => $data ) {
			$urls = array_keys( $data['urls'] );
			sort( $urls );

			foreach ( $urls as $url ) {
				$inventory[] = array(
					'block'       => $block,
					'plugin'      => $data['owner']['name'],
					'plugin_slug' => $data['owner']['slug'],
					'occurrences' => $data['occurrences'],
					'url'         => $url,
				);
			}
		}

		return $inventory;
	}

	/**
	 * Writes an .xlsx spreadsheet workbook: wrapped text, explicit
	 * column widths (capped around 5"), a bold header row, an
	 * autofilter, and the header row + the blog_id and original_url
	 * columns frozen so they stay on screen while scrolling
	 * right/down through the detector columns.
	 *
	 * The blog_id and original_url columns come first specifically so
	 * they can be the frozen columns -- the identity of a row stays
	 * visible no matter how far right you scroll.
	 *
	 * A second worksheet tab (`block-inventory`) aggregates the
	 * non-core blocks used across the network with owning plugin,
	 * occurrences, and sample URLs -- the main sheet's "blocks" cell
	 * is one semicolon-joined string per row that spreadsheet filters
	 * can't explode. When needs-review details are passed, a third tab
	 * (`needs-review`) carries the same rows as the
	 * site-audit-needs-review CSV.
	 *
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories
	 * @param array             $pluginUsage Optional PluginUsageRollup::build() result, used to
	 *                                       resolve block owners on the block-inventory tab.
	 * @param array             $needsReviewDetails Optional NeedsReviewReportWriter input; adds a
	 *                                       "needs-review" tab.
	 * @param string            $destinationUrl     Destination site URL, for that tab's URL guess.
	 */
	public function toXlsx( array $rows, array $categories, string $path, array $pluginUsage = array(), array $needsReviewDetails = array(), string $destinationUrl = '' ): void {
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
		// Office ships Consolas on Windows AND macOS, and fontconfig maps it on
		// Linux (to DejaVu Sans Mono on Linux Mint). (Cascadia Mono, Menlo and
		// ui-monospace all fall back to PROPORTIONAL fonts on Linux --
		// worse than the Calibri default they were meant to replace.
		// 'ui-monospace' is not a font, but a CSS generic-family keyword)
		$spreadsheet->getDefaultStyle()->getFont()
			->setName( 'Consolas' )
			->setSize( 12 );
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
			SpreadsheetRowHeight::apply( $sheet, $rowIndex + 2, $rowData, $columns, $widths );
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

		$this->addBlockInventorySheet( $spreadsheet, $this->blockInventory( $rows, $pluginUsage ) );

		if ( $needsReviewDetails !== array() && $destinationUrl !== '' ) {
			$this->addNeedsReviewSheet( $spreadsheet, $needsReviewDetails, $destinationUrl );
		}

		( new Xlsx( $spreadsheet ) )->save( $path );
		$spreadsheet->disconnectWorksheets();
	}

	/**
	 * Second worksheet tab: one row per (block, page) pair with owning
	 * plugin and per-block occurrence total, so either column can be
	 * filtered for a full usage list. Always created (with headers even
	 * when no blocks were found) so the tab's presence is stable across
	 * runs.
	 *
	 * @param array<int, array{block: string, plugin: string, plugin_slug: string, occurrences: int, url: string}> $inventory
	 */
	private function addBlockInventorySheet( Spreadsheet $spreadsheet, array $inventory ): void {
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle( 'block-inventory' );

		$columns = array(
			'block'       => 'Block (Namespace/Name)',
			'plugin'      => 'Owning Plugin',
			'plugin_slug' => 'Plugin Slug',
			'occurrences' => 'Occurrences',
			'url'         => 'Used On URL',
		);
		$widths = array(
			'block'       => 36,
			'plugin'      => 32,
			'plugin_slug' => 32,
			'occurrences' => 12,
			'url'         => 60,
		);

		$columnKeys = array_keys( $columns );
		foreach ( $columnKeys as $index => $key ) {
			$letter = Coordinate::stringFromColumnIndex( $index + 1 );
			$sheet->setCellValue( $letter . '1', $columns[ $key ] );
			$sheet->getColumnDimension( $letter )->setWidth( $widths[ $key ] );
		}

		foreach ( $inventory as $rowIndex => $entry ) {
			foreach ( $columnKeys as $index => $key ) {
				$letter = Coordinate::stringFromColumnIndex( $index + 1 );
				$sheet->setCellValue( $letter . ( $rowIndex + 2 ), $entry[ $key ] );
			}
			SpreadsheetRowHeight::apply( $sheet, $rowIndex + 2, $entry, $columnKeys, $widths );
		}

		$lastColumn = Coordinate::stringFromColumnIndex( count( $columns ) );
		$lastRow    = count( $inventory ) + 1;
		$wholeRange = 'A1:' . $lastColumn . $lastRow;

		$sheet->getStyle( 'A1:' . $lastColumn . '1' )->getFont()->setBold( true );
		$sheet->getStyle( $wholeRange )->getAlignment()
			->setWrapText( true )
			->setVertical( Alignment::VERTICAL_TOP );
		$sheet->freezePane( 'A2' );
		$sheet->setAutoFilter( $wholeRange );
	}

	/**
	 * Third worksheet tab: the same pages as the
	 * site-audit-needs-review CSV (original + guessed destination URL,
	 * detected plugins, raw data dumps), so reviewers can work from
	 * the workbook alone. Reuses NeedsReviewReportWriter::toRows() so
	 * the tab and the CSV never drift apart.
	 *
	 * @param array<int, array{row: ContentAuditRow, post: \MergeMultisite\ContentAudit\ScannedPost}> $details
	 */
	private function addNeedsReviewSheet( Spreadsheet $spreadsheet, array $details, string $destinationUrl ): void {
		$sheet = $spreadsheet->createSheet();
		$sheet->setTitle( 'needs-review' );

		$columns = NeedsReviewReportWriter::columns();
		$widths = array(
			'blog_id'                    => 9,
			'original_url'               => 50,
			'destination_url (guess)'    => 50,
			'post_id'                    => 10,
			'post_type'                  => 14,
			'post_status'                => 10,
			'post_title'                 => 45,
			'plugins_that_need_checking' => 40,
			'raw_data'                   => 90,
		);

		foreach ( $columns as $index => $key ) {
			$letter = Coordinate::stringFromColumnIndex( $index + 1 );
			$sheet->setCellValue( $letter . '1', $key );
			$sheet->getColumnDimension( $letter )->setWidth( $widths[ $key ] ?? 40 );
		}

		$needsReviewRows = ( new NeedsReviewReportWriter() )->toRows( $details, $destinationUrl );
		foreach ( $needsReviewRows as $rowIndex => $row ) {
			foreach ( $columns as $index => $key ) {
				$letter = Coordinate::stringFromColumnIndex( $index + 1 );
				$sheet->setCellValue( $letter . ( $rowIndex + 2 ), $row[ $key ] ?? '' );
			}
			SpreadsheetRowHeight::apply( $sheet, $rowIndex + 2, $row, $columns, $widths );
		}

		$lastColumn  = Coordinate::stringFromColumnIndex( count( $columns ) );
		$lastRow     = count( $needsReviewRows ) + 1;
		$wholeRange  = 'A1:' . $lastColumn . $lastRow;

		$sheet->getStyle( 'A1:' . $lastColumn . '1' )->getFont()->setBold( true );
		$sheet->getStyle( $wholeRange )->getAlignment()
			->setWrapText( true )
			->setVertical( Alignment::VERTICAL_TOP );
		$sheet->freezePane( 'A2' );
		$sheet->setAutoFilter( $wholeRange );
	}
}
