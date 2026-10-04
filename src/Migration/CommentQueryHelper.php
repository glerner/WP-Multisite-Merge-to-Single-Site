<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Shared query helper for comments and commentmeta, mirroring
 * PostQueryHelper (PLAN.md §7.4).
 *
 * Comments are chunked two ways so arbitrarily large comment tables
 * stay memory-bounded and under MySQL's 65535 prepared-statement
 * parameter limit: keyset pagination for the comment rows themselves,
 * and IN()-chunked fetching for commentmeta.
 *
 * Used by CommentMigrator; OrphanedMetaCheck's aggregate orphan COUNTs
 * don't need chunking and query the tables directly.
 *
 * @package MergeMultisite
 */
final class CommentQueryHelper {

	/**
	 * Yields comment rows in keyset-ordered batches
	 * (`comment_ID > :last_id ORDER BY comment_ID ASC LIMIT n`), the
	 * same pattern MalwareIndicatorCheck uses for posts — OFFSET-free
	 * so large tables don't slow down quadratically.
	 *
	 * @return \Generator<int, array<int, array<string, mixed>>> batch number => comment rows
	 */
	public static function commentBatches( Connection $connection, string $commentsTable, int $batchSize = 500 ): \Generator {
		$lastId = 0;
		$batch = 0;

		do {
			$rows = $connection->fetchAll(
				"SELECT * FROM {$commentsTable}
                 WHERE comment_ID > :last_id
                 ORDER BY comment_ID ASC
                 LIMIT " . (int) $batchSize,
				array( 'last_id' => $lastId )
			);

			if ( $rows === array() ) {
				break;
			}

			yield $batch++ => $rows;
			$rowCount = count( $rows );
			$lastId = (int) $rows[ $rowCount - 1 ]['comment_ID'];
		} while ( $rowCount === $batchSize );
	}

	/**
	 * Fetches commentmeta for a list of comment IDs, chunked into
	 * 5000 IDs per query to stay well under MySQL's 65535 parameter
	 * limit (same bound as PostQueryHelper::fetchMetaForPosts).
	 *
	 * @param int[] $commentIds
	 *
	 * @return array<int, array<string, string[]>> comment_id => meta_key => list of meta_values
	 */
	public static function fetchMetaForComments( Connection $connection, string $commentMetaTable, array $commentIds ): array {
		$metaByComment = array();

		foreach ( array_chunk( $commentIds, 5000 ) as $chunk ) {
			$placeholders = array();
			$params = array();
			foreach ( $chunk as $index => $commentId ) {
				$key = 'comment_id_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $commentId;
			}

			$rows = $connection->fetchAll(
				"SELECT comment_id, meta_key, meta_value FROM {$commentMetaTable} WHERE comment_id IN (" . implode( ', ', $placeholders ) . ')',
				$params
			);

			foreach ( $rows as $row ) {
				$metaByComment[ (int) $row['comment_id'] ][ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
			}
		}

		return $metaByComment;
	}
}
