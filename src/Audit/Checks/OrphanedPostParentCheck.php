<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Flags posts whose `post_parent` is non-zero but does not reference
 * an existing post on the same site.
 *
 * @package MergeMultisite
 */
final class OrphanedPostParentCheck implements AuditCheckInterface {

	public function name(): string {
		return 'orphaned-post-parent';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT child.ID, child.post_title, child.post_parent
                 FROM {$postsTable} child
                 LEFT JOIN {$postsTable} parent ON parent.ID = child.post_parent
                 WHERE child.post_parent != 0 AND parent.ID IS NULL"
			);

			foreach ( $rows as $row ) {
				$findings[] = AuditFinding::error(
					$this->name(),
					sprintf(
						'Post %d ("%s", site %d) has post_parent=%d, which does not exist on that site.',
						(int) $row['ID'],
						(string) $row['post_title'],
						$site->blogId,
						(int) $row['post_parent']
					),
					array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'post_parent' => (int) $row['post_parent'],
					)
				);
			}
		}

		return $findings;
	}
}
