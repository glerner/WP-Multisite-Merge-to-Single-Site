<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Flags posts whose `post_author` does not reference an existing row
 * in the network-wide `users` table.
 *
 * @package MergeMultisite
 */
final class OrphanedPostAuthorCheck implements AuditCheckInterface {

	public function name(): string {
		return 'orphaned-post-author';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();
		$usersTable = $source->networkTable( 'users' );

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT p.ID, p.post_title, p.post_type, p.post_author
                 FROM {$postsTable} p
                 LEFT JOIN {$usersTable} u ON u.ID = p.post_author
                 WHERE u.ID IS NULL"
			);

			foreach ( $rows as $row ) {
				$postAuthor = (int) $row['post_author'];

				if ( $postAuthor === 0 ) {
					// post_author=0 means "no author" and is used
					// legitimately by WordPress core and plugins for
					// system content (e.g. default "Navigation" pages,
					// WooCommerce's placeholder image). Reported as
					// informational only, so these don't drown out
					// genuinely broken rows.
					$findings[] = AuditFinding::info(
						$this->name() . '.no-author',
						sprintf(
							'Post %d ("%s", site %d) has post_author=0 (no author). Normal for system-created content (Navigation pages, plugin placeholders); only worth a closer look if it is neither.',
							(int) $row['ID'],
							(string) $row['post_title'],
							$site->blogId
						),
						array(
							'blog_id' => $site->blogId,
							'post_id' => (int) $row['ID'],
							'post_author' => 0,
						)
					);
					continue;
				}

				$findings[] = AuditFinding::error(
					$this->name(),
					sprintf(
						'Post %d ("%s", site %d) has post_author=%d, which does not exist.',
						(int) $row['ID'],
						(string) $row['post_title'],
						$site->blogId,
						$postAuthor
					),
					array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'post_author' => $postAuthor,
					)
				);
			}
		}

		return $findings;
	}
}
