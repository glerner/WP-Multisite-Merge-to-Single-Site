<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use MergeMultisite\ContentAudit\ContentAuditRow;

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
	 * @param string[]          $categories Every detector category, in the order columns should appear.
	 *
	 * @return array{csv: string, json: string, summary: string}
	 */
	public function write( array $rows, array $categories, string $outputDirectory, string $baseName ): array {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$csvPath     = $outputDirectory . '/' . $baseName . '.csv';
		$jsonPath    = $outputDirectory . '/' . $baseName . '.json';
		$summaryPath = $outputDirectory . '/' . $baseName . '-summary.md';

		file_put_contents( $csvPath, $this->toCsv( $rows, $categories ) );
		file_put_contents( $jsonPath, $this->toJson( $rows ) );
		file_put_contents( $summaryPath, $this->toSummary( $rows, $categories ) );

		return array(
			'csv'     => $csvPath,
			'json'    => $jsonPath,
			'summary' => $summaryPath,
		);
	}

	/**
	 * @param ContentAuditRow[] $rows
	 * @param string[]          $categories
	 */
	public function toCsv( array $rows, array $categories ): string {
		$handle = fopen( 'php://temp', 'w+' );

		$header = array( 'blog_id', 'domain', 'post_id', 'post_type', 'post_status', 'slug', 'url', ...$categories );
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
	 */
	public function toSummary( array $rows, array $categories ): string {
		$lines = array( '# Content/Plugin Usage Summary', '' );

		foreach ( $categories as $category ) {
			$tally = array();

			foreach ( $rows as $row ) {
				foreach ( $row->categoryFindings[ $category ] ?? array() as $label ) {
					$tally[ $label ] = ( $tally[ $label ] ?? 0 ) + 1;
				}
			}

			if ( $tally === array() ) {
				continue;
			}

			arsort( $tally );

			$lines[] = sprintf( '## %s', $category );
			$lines[] = '';
			foreach ( $tally as $label => $count ) {
				$lines[] = sprintf( '- %s: %d page(s)', $label, $count );
			}
			$lines[] = '';
		}

		return implode( PHP_EOL, $lines ) . PHP_EOL;
	}
}
