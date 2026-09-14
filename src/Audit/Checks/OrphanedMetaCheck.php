<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Flags `postmeta`/`commentmeta` rows whose parent post/comment no
 * longer exists -- typically leftovers from a post or comment being
 * deleted without WordPress cleaning up its meta (e.g. direct DB
 * edits, or a buggy plugin).
 *
 * @package MergeMultisite
 */
final class OrphanedMetaCheck implements AuditCheckInterface {

	public function name(): string {
		return 'orphaned-meta';
	}

	public function description(): string {
		return 'postmeta/commentmeta rows whose parent post/comment no longer exists -- leftovers from deletions that skipped cleanup.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		foreach ( $sites as $site ) {
			$findings = array( ...$findings, ...$this->checkPostMeta( $source, $site->blogId ) );
			$findings = array( ...$findings, ...$this->checkCommentMeta( $source, $site->blogId ) );
		}

		return $findings;
	}

	/**
	 * @return AuditFinding[]
	 */
	private function checkPostMeta( Connection $source, int $blogId ): array {
		$postMetaTable = $source->siteTable( 'postmeta', $blogId );
		$postsTable = $source->siteTable( 'posts', $blogId );

		// One row per meta_key (with counts), so a huge count like
		// 7,000 rows becomes one finding naming the actual key(s)
		// involved instead of a bare scary number. Common causes of
		// orphaned postmeta: plugins that delete posts with direct SQL,
		// or postmeta rows imported/migrated without their posts.
		$rows = $source->fetchAll(
			"SELECT pm.meta_key, COUNT(*) AS orphan_count
             FROM {$postMetaTable} pm
             LEFT JOIN {$postsTable} p ON p.ID = pm.post_id
             WHERE p.ID IS NULL
             GROUP BY pm.meta_key
             ORDER BY orphan_count DESC"
		);

		if ( $rows === array() ) {
			return array();
		}

		$total = 0;
		$summary = array();
		foreach ( $rows as $row ) {
			$count = (int) $row['orphan_count'];
			$total += $count;
			$summary[] = sprintf( '%s x%d', (string) $row['meta_key'], $count );
		}

		return array(
		AuditFinding::warning(
			$this->name(),
			sprintf(
				'Site %d, postmeta: %d orphaned row(s) by meta_key: %s.',
				$blogId,
				$total,
				implode( ', ', array_slice( $summary, 0, 10 ) ) . ( count( $summary ) > 10 ? sprintf( ' (and %d more meta_key(s))', count( $summary ) - 10 ) : '' )
			),
			array(
				'blog_id' => $blogId,
				'orphaned_postmeta_count' => $total,
				'by_meta_key' => array_map(
					static fn ( array $row ): array => array(
						'meta_key' => $row['meta_key'],
						'count' => (int) $row['orphan_count'],
					),
					$rows
				),
			)
		),
		);
	}

	/**
	 * @return AuditFinding[]
	 */
	private function checkCommentMeta( Connection $source, int $blogId ): array {
		$commentMetaTable = $source->siteTable( 'commentmeta', $blogId );
		$commentsTable = $source->siteTable( 'comments', $blogId );

		$count = (int) $source->fetchScalar(
			"SELECT COUNT(*) FROM {$commentMetaTable} cm
             LEFT JOIN {$commentsTable} c ON c.comment_ID = cm.comment_id
             WHERE c.comment_ID IS NULL"
		);

		if ( $count === 0 ) {
			return array();
		}

		return array(
		AuditFinding::warning(
			$this->name(),
			sprintf( 'Site %d, commentmeta: %d orphaned row(s).', $blogId, $count ),
			array(
			'blog_id' => $blogId,
			'orphaned_commentmeta_count' => $count,
			)
		),
		);
	}
}
