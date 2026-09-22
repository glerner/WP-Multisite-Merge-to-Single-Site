<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * The `{prefix}merge_migration_map` table on the DESTINATION database:
 * records every migrated row's origin (entity type, source blog_id,
 * source ID) so a re-run or --resume detects "already migrated" and
 * skips instead of double-inserting (PLAN.md §10).
 *
 * Note: ensure() is DDL and MUST be called before any batch
 * transaction opens -- MySQL implicitly commits around DDL, which
 * would silently commit pending writes and make rollBack() a no-op.
 *
 * @package MergeMultisite
 */
final class MigrationTable {

	private const SUFFIX = 'merge_migration_map';

	/**
	 * The map table name for this destination's prefix.
	 */
	public function tableName( Connection $destination ): string {
		return $destination->config->tablePrefix . self::SUFFIX;
	}

	/**
	 * Create the map table if missing. DDL -- call BEFORE opening any
	 * batch transaction (see class docblock).
	 */
	public function ensure( Connection $destination ): void {
		$table = $this->tableName( $destination );
		$destination->execute(
			"CREATE TABLE IF NOT EXISTS {$table} (
                entity_type    VARCHAR(32)     NOT NULL,
                source_blog_id INT             NOT NULL DEFAULT 0,
                source_id      VARCHAR(191)    NOT NULL,
                dest_id        BIGINT UNSIGNED NOT NULL,
                migrated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_type, source_blog_id, source_id),
                KEY dest_id (dest_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	}

	/**
	 * Load every recorded mapping into the given IdMap (resume path).
	 */
	public function prewarm( Connection $destination, IdMap $idMap ): int {
		$table = $this->tableName( $destination );
		if ( ! $destination->tableExists( $table ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $destination->fetchAll( "SELECT entity_type, source_blog_id, source_id, dest_id FROM {$table}" ) as $row ) {
			$idMap->set( (string) $row['entity_type'], (int) $row['source_blog_id'], (string) $row['source_id'], (int) $row['dest_id'] );
			++$count;
		}

		return $count;
	}

	/**
	 * Record one migrated row's origin -> destination ID.
	 */
	public function record( Connection $destination, string $type, int $siteId, int|string $sourceId, int $destId ): void {
		$table = $this->tableName( $destination );
		$destination->execute(
			"INSERT IGNORE INTO {$table} (entity_type, source_blog_id, source_id, dest_id) VALUES (:type, :blog, :source, :dest)",
			array(
				'type'   => $type,
				'blog'   => $siteId,
				'source' => (string) $sourceId,
				'dest'   => $destId,
			)
		);
	}
}
