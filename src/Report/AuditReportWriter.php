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
	 * @param AuditFinding[]       $findings
	 * @param array<string,string> $checkDescriptions check name => one-line
	 *                                description, printed once under each
	 *                                section heading.
	 *
	 * @return array{markdown: string, json: string} Absolute paths of the two files written.
	 */
	public function write( array $findings, string $outputDirectory, string $baseName, array $checkDescriptions = array() ): array {
		if ( ! is_dir( $outputDirectory ) ) {
			mkdir( $outputDirectory, 0775, true );
		}

		$markdownPath = $outputDirectory . '/' . $baseName . '.md';
		$jsonPath = $outputDirectory . '/' . $baseName . '.json';

		file_put_contents( $markdownPath, $this->toMarkdown( $findings, $checkDescriptions ) );
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
	 * @param AuditFinding[]       $findings
	 * @param array<string,string> $checkDescriptions
	 */
	public function toMarkdown( array $findings, array $checkDescriptions = array() ): string {
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

		foreach ( $this->actionGuideLines() as $guideLine ) {
			$lines[] = $guideLine;
		}

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

				// Findings are often named "<check>.<subcode>"; the
				// description is registered under the base check name.
				$baseName = strstr( $checkName, '.', true );
				$description = $checkDescriptions[ $checkName ]
					?? $checkDescriptions[ $baseName === false ? $checkName : $baseName ]
					?? null;
				if ( $description !== null ) {
					$lines[] = $description;
					$lines[] = '';
				}

				foreach ( $checkFindings as $finding ) {
					$lines[] = '- ' . $finding->message;
				}
				$lines[] = '';
			}
		}

		return implode( PHP_EOL, $lines ) . PHP_EOL;
	}

	/**
	 * Generates the Action & Resolution Guide markdown table providing concrete
	 * instructions on inspecting, fixing, or suppressing findings from each check.
	 *
	 * @return string[]
	 */
	private function actionGuideLines(): array {
		return array(
			'## Action & Resolution Guide',
			'',
			'| Check Name | Where to Inspect / How to Fix at Source | How to Suppress or Configure |',
			'|:---|:---|:---|',
			'| **orphaned-post-author** | Subsite wp-admin → edit post and reassign author, or delete post | Add `[\'check\' => \'orphaned-post-author\', \'post_id\' => ...]` in `suppressions` section of `config/config.php` |',
			'| **orphaned-post-parent** | Subsite wp-admin → edit page attributes, set parent to top-level or valid page | Add `[\'check\' => \'orphaned-post-parent\', \'post_type\' => \'...\']` in `suppressions` section of `config/config.php` |',
			'| **orphaned-meta** | Source database: delete meta rows whose `post_id` or `comment_id` no longer exists | Add `[\'check\' => \'orphaned-meta*\']` in `suppressions` section of `config/config.php` |',
			'| **user-conflicts** | Network Admin → Users: merge accounts or update email to be unique | Add `[\'check\' => \'user-conflicts\', \'login\' => \'...\']` in `suppressions` section of `config/config.php` |',
			'| **media-files** | Inspect files via `var/reports/copy-missing-media-*.sh` or source `wp-content/uploads/` | Auto-handled: collisions are renamed to `{basename}_site{id}` during migration |',
			'| **term-case-collisions** | Subsite wp-admin → Posts → Categories/Tags: align case variants | Override canonical label via `taxonomy => [\'old\' => \'Canonical\']` in `config/term-overrides.php` |',
			'| **template-slug-collision** | Subsite wp-admin → Site Editor → Templates: review colliding template slugs | Set `\'main_site\' => <blog_id>` in `config/config.php` to choose the winning template |',
			'| **plugin-data** | Inspect options in database or installed plugins in `wp-content/plugins/` | Add `mode => \'include\'` or `mode => \'exclude\'` for the slug in `config/option-keys.php` |',
			'| **pods-detection** | Advisory check: inspect if Pods custom tables contain real content | Advisory check only; no action needed unless custom content types require migration |',
			'| **menu-widget-integrity** | Subsite wp-admin → Appearance → Menus / Widgets: remove broken links or rebuild widget | Fix broken menu targets in wp-admin or rebuild corrupted widget instances |',
			'| **contact-page-discovery** | Advisory preview of contact page URLs that will canonicalize to `/contact/` | Advisory for 301 redirect map generation in `PLAN.md` §7.1 |',
			'| **malware-indicators** | Subsite wp-admin → edit post and remove injected spam keywords | Add `[\'check\' => \'malware-indicators.spam-keyword\', \'post_id\' => ...]` in `suppressions` of `config/config.php` |',
			'| **orphaned-media-files** | Run generated `var/reports/copy-missing-media-*.sh` to copy files from search paths | Restore files or delete broken attachment posts in subsite Media Library |',
			'| **divergent-site-options** | Subsite wp-admin → Settings: harmonize differing options across subsites | Informational decision guide: merged single site keeps one canonical value per option |',
			'',
		);
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
