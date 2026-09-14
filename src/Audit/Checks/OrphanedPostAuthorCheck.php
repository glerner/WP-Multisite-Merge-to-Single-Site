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

	public function description(): string {
		return 'Posts whose post_author matches no user. post_author=0 is normal for system-created content (Navigation pages, plugin placeholders).';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();
		$usersTable = $source->networkTable( 'users' );

		$typeExclusion = '';
		$typeParams = array();
		if ( $config->excludedPostTypes !== array() ) {
			$typeExclusion = ' AND p.post_type NOT IN ('
				. implode( ', ', array_fill( 0, count( $config->excludedPostTypes ), '?' ) )
				. ')';
			$typeParams = $config->excludedPostTypes;
		}

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT p.ID, p.post_title, p.post_type, p.post_author
                 FROM {$postsTable} p
                 LEFT JOIN {$usersTable} u ON u.ID = p.post_author
                 WHERE u.ID IS NULL{$typeExclusion}",
				$typeParams
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
							'Site %d, Post %d "%s" (%s) post_author=0 -- normal for system-created content.',
							$site->blogId,
							(int) $row['ID'],
							(string) $row['post_title'],
							(string) $row['post_type']
						),
						array(
							'blog_id' => $site->blogId,
							'post_id' => (int) $row['ID'],
							'post_type' => (string) $row['post_type'],
							'post_author' => 0,
						)
					);
					continue;
				}

				$findings[] = AuditFinding::error(
					$this->name(),
					sprintf(
						'Site %d, Post %d "%s" (%s) post_author=%d -- author does not exist.',
						$site->blogId,
						(int) $row['ID'],
						(string) $row['post_title'],
						(string) $row['post_type'],
						$postAuthor
					),
					array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'post_type' => (string) $row['post_type'],
					'post_author' => $postAuthor,
					)
				);
			}
		}

		return $findings;
	}
}
