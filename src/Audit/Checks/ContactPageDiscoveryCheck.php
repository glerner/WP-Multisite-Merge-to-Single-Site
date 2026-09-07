<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Discovers contact-page slug variants across included sites, both to
 * confirm the `contact_page_paths` list in config.php before the
 * "canonicalize to /contact/" migration step (PLAN.md §7.1), and to
 * surface any variant you didn't already know about (e.g. beyond
 * `/contact/` and `/contact-me/`).
 *
 * @package MergeMultisite
 */
final class ContactPageDiscoveryCheck implements AuditCheckInterface {

	public function name(): string {
		return 'contact-page-discovery';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();
		$knownPaths = array_map( static fn ( string $p ): string => trim( $p, '/' ), $config->contactPagePaths );

		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT ID, post_name FROM {$postsTable}
                 WHERE post_type IN ('page', 'post') AND post_status NOT IN ('trash', 'auto-draft')
                 AND post_name LIKE '%contact%'"
			);

			foreach ( $rows as $row ) {
				$slug = (string) $row['post_name'];
				$isKnown = in_array( $slug, $knownPaths, true );

				$findings[] = AuditFinding::info(
					$this->name(),
					sprintf(
						'Site %d has a contact-like page "/%s/" (post %d)%s.',
						$site->blogId,
						$slug,
						(int) $row['ID'],
						$isKnown ? ' (already in contact_page_paths)' : ' -- NOT in contact_page_paths, consider adding it'
					),
					array(
					'blog_id' => $site->blogId,
					'post_id' => (int) $row['ID'],
					'slug' => $slug,
					'known' => $isKnown,
					)
				);
			}
		}

		return $findings;
	}
}
