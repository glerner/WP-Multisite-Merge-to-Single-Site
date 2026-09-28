<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Shared query helper for posts and postmeta: builds standard post_status
 * and post_type WHERE clauses (respecting exclusion lists) and fetches
 * postmeta chunked to prevent MySQL 65535 prepared statement limits.
 *
 * Shared by PostScanner (site-audit.php) and PostMigrator (migrate.php).
 *
 * @package MergeMultisite
 */
final class PostQueryHelper {

	/**
	 * Builds the post_status WHERE fragment: 'trash' and 'auto-draft'
	 * are always excluded (never real content), plus whatever
	 * excluded_post_statuses config adds (e.g. 'draft', 'inherit').
	 *
	 * @param string[]              $excludedPostStatuses
	 * @param array<string, string> $params Bound parameters, appended.
	 */
	public static function postStatusClause( array $excludedPostStatuses, array &$params ): string {
		$statuses = array_values( array_unique( array_merge( array( 'trash', 'auto-draft' ), $excludedPostStatuses ) ) );

		$placeholders = array();
		foreach ( $statuses as $index => $status ) {
			$key            = 'post_status_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $status;
		}

		return sprintf( 'post_status NOT IN (%s)', implode( ', ', $placeholders ) );
	}

	/**
	 * Builds the post_type WHERE fragment: an IN() allow-list when
	 * $postTypes is given, otherwise a NOT IN() exclusion list (so an
	 * explicit --post-types selection can still audit an excluded type
	 * like "revision" on purpose).
	 *
	 * @param string[]              $postTypes
	 * @param string[]              $excludedPostTypes
	 * @param array<string, string> $params Bound parameters, appended.
	 */
	public static function postTypeClause( array $postTypes, array $excludedPostTypes, array &$params ): string {
		$types = $postTypes !== array() ? $postTypes : null;

		if ( $types !== null ) {
			$placeholders = array();
			foreach ( array_values( $types ) as $index => $type ) {
				$key            = 'post_type_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $type;
			}
			return sprintf( ' AND post_type IN (%s)', implode( ', ', $placeholders ) );
		}

		if ( $excludedPostTypes === array() ) {
			return '';
		}

		$placeholders = array();
		foreach ( array_values( $excludedPostTypes ) as $index => $type ) {
			$key            = 'excluded_post_type_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $type;
		}

		return sprintf( ' AND post_type NOT IN (%s)', implode( ', ', $placeholders ) );
	}

	/**
	 * Fetches postmeta for a list of post IDs, chunked into 5000 IDs per
	 * query to stay well under MySQL's 65535 parameter limit.
	 *
	 * @param int[] $postIds
	 *
	 * @return array<int, array<string, string[]>> post_id => meta_key => list of meta_values
	 */
	public static function fetchMetaForPosts( Connection $connection, string $postMetaTable, array $postIds ): array {
		$metaByPost = array();

		foreach ( array_chunk( $postIds, 5000 ) as $chunk ) {
			$placeholders = array();
			$params       = array();
			foreach ( $chunk as $index => $postId ) {
				$key            = 'post_id_' . $index;
				$placeholders[] = ':' . $key;
				$params[ $key ] = $postId;
			}

			$rows = $connection->fetchAll(
				"SELECT post_id, meta_key, meta_value FROM {$postMetaTable} WHERE post_id IN (" . implode( ', ', $placeholders ) . ')',
				$params
			);

			foreach ( $rows as $row ) {
				$metaByPost[ (int) $row['post_id'] ][ (string) $row['meta_key'] ][] = (string) $row['meta_value'];
			}
		}

		return $metaByPost;
	}
}
