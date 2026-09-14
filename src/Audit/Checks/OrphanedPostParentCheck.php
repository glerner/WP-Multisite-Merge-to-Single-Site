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

	public function description(): string {
		return 'Posts whose post_parent points at a post that does not exist on that site.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		$typeExclusion = '';
		$typeParams = array();
		if ( $config->excludedPostTypes !== array() ) {
			$typeExclusion = ' AND child.post_type NOT IN ('
				. implode( ', ', array_fill( 0, count( $config->excludedPostTypes ), '?' ) )
				. ')';
			$typeParams = $config->excludedPostTypes;
		}

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT child.ID, child.post_title, child.post_type, child.post_parent
                 FROM {$postsTable} child
                 LEFT JOIN {$postsTable} parent ON parent.ID = child.post_parent
                 WHERE child.post_parent != 0 AND parent.ID IS NULL{$typeExclusion}",
				$typeParams
			);

			foreach ( $rows as $row ) {
				$findings[] = AuditFinding::error(
					$this->name(),
					sprintf(
						'Site %d, Post %d "%s" (%s) post_parent=%d -- parent does not exist on that site.',
						$site->blogId,
						(int) $row['ID'],
						(string) $row['post_title'],
						(string) $row['post_type'],
						(int) $row['post_parent']
					),
					array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'post_type' => (string) $row['post_type'],
					'post_parent' => (int) $row['post_parent'],
					)
				);
			}
		}

		return $findings;
	}
}
