<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use MergeMultisite\Audit\AuditFinding;

/**
 * Renders a set of AuditFinding objects as a human-readable Markdown
 * report and a machine-readable JSON report, and writes both to disk.
 *
 * @package MergeMultisite
 */
final class AuditReportWriter {

	/**
	 * @param AuditFinding[] $findings
	 *
	 * @return array{markdown: string, json: string} Absolute paths of the two files written.
	 */
	public function write( array $findings, string $outputDirectory, string $baseName ): array {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$markdownPath = $outputDirectory . '/' . $baseName . '.md';
		$jsonPath = $outputDirectory . '/' . $baseName . '.json';

		file_put_contents( $markdownPath, $this->toMarkdown( $findings ) );
		file_put_contents( $jsonPath, $this->toJson( $findings ) );

		return array(
		'markdown' => $markdownPath,
		'json' => $jsonPath,
		);
	}

	/**
	 * @param AuditFinding[] $findings
	 */
	public function toJson( array $findings ): string {
		$payload = array(
			'generated_at' => date( DATE_ATOM ),
			'summary' => $this->summarize( $findings ),
			'findings' => array_map( static fn ( AuditFinding $f ): array => $f->toArray(), $findings ),
		);

		return (string) json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param AuditFinding[] $findings
	 */
	public function toMarkdown( array $findings ): string {
		$summary = $this->summarize( $findings );

		$lines = array();
		$lines[] = '# Multisite Integrity Report';
		$lines[] = '';
		$lines[] = sprintf( 'Generated: %s', date( DATE_ATOM ) );
		$lines[] = '';
		$lines[] = sprintf(
			'**Summary:** %d error(s), %d warning(s), %d informational finding(s).',
			$summary[ AuditFinding::SEVERITY_ERROR ],
			$summary[ AuditFinding::SEVERITY_WARNING ],
			$summary[ AuditFinding::SEVERITY_INFO ]
		);
		$lines[] = '';

		foreach ( array( AuditFinding::SEVERITY_ERROR, AuditFinding::SEVERITY_WARNING, AuditFinding::SEVERITY_INFO ) as $severity ) {
			$group = array_values( array_filter( $findings, static fn ( AuditFinding $f ): bool => $f->severity === $severity ) );

			if ( $group === array() ) {
				continue;
			}

			$lines[] = sprintf( '## %s (%d)', ucfirst( $severity ) . 's', count( $group ) );
			$lines[] = '';

			$byCheck = array();
			foreach ( $group as $finding ) {
				$byCheck[ $finding->checkName ][] = $finding;
			}

			foreach ( $byCheck as $checkName => $checkFindings ) {
				$lines[] = sprintf( '### %s', $checkName );
				$lines[] = '';
				foreach ( $checkFindings as $finding ) {
					$lines[] = '- ' . $finding->message;
				}
				$lines[] = '';
			}
		}

		return implode( PHP_EOL, $lines ) . PHP_EOL;
	}

	/**
	 * @param AuditFinding[] $findings
	 *
	 * @return array<string, int>
	 */
	private function summarize( array $findings ): array {
		$summary = array(
			AuditFinding::SEVERITY_ERROR => 0,
			AuditFinding::SEVERITY_WARNING => 0,
			AuditFinding::SEVERITY_INFO => 0,
		);

		foreach ( $findings as $finding ) {
			$summary[ $finding->severity ]++;
		}

		return $summary;
	}
}
