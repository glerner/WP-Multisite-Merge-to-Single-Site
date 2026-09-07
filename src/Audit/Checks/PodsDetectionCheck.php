<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Detects whether the Pods plugin is installed/configured on any
 * included site, and whether it has created any custom database
 * tables (its "Advanced Content Types" feature). A dedicated
 * PodsAdapter migrator is only worth building once this confirms Pods
 * is actually in use (PLAN.md §7.5).
 *
 * @package MergeMultisite
 */
final class PodsDetectionCheck implements AuditCheckInterface {

	public function name(): string {
		return 'pods-detection';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		$customTables = $source->fetchAll(
			'SELECT TABLE_NAME FROM information_schema.TABLES '
			. 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME LIKE :pattern',
			array(
			'schema' => $config->source->database,
			'pattern' => '%pods%',
			)
		);

		if ( $customTables !== array() ) {
			$tableNames = array_column( $customTables, 'TABLE_NAME' );

			// The full table list stays in the JSON report's context;
			// the terminal-facing message shows only a sample so a long
			// list (e.g. dozens of tables) doesn't push the real
			// findings off screen.
			$sample = array_slice( $tableNames, 0, 5 );

			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'Found %d Pods-related custom table(s) (e.g. %s). These are likely leftovers from a time when the Pods plugin was network-enabled, not proof it is still in use. A dedicated PodsAdapter migrator is only needed if any site actually still uses Pods content. Full list in the JSON report.',
					count( $tableNames ),
					implode( ', ', array_map( static fn ( string $t ): string => '"' . $t . '"', $sample ) )
				),
				array( 'tables' => $tableNames )
			);
		}

		foreach ( $sites as $site ) {
			$optionsTable = $source->siteTable( 'options', $site->blogId );

			$count = (int) $source->fetchScalar(
				"SELECT COUNT(*) FROM {$optionsTable} WHERE option_name LIKE 'pods%'"
			);

			if ( $count > 0 ) {
				$findings[] = AuditFinding::info(
					$this->name(),
					sprintf( 'Site %d has %d Pods-related option row(s) -- Pods appears to be configured there.', $site->blogId, $count ),
					array(
					'blog_id' => $site->blogId,
					'count' => $count,
					)
				);
			}
		}

		return $findings;
	}
}
