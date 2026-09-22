<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\TemplateInventory;

/**
 * Detects wp_template / wp_template_part posts (block-theme templates;
 * headers and footers live in wp_template_part) that share a post_name
 * slug across included sites. On merge only one post per slug can
 * survive at the destination, so a collision means N sites' headers or
 * templates compete for one row -- when 'main_site' is configured its
 * variant wins deliberately rather than by insertion order.
 *
 * @package MergeMultisite
 */
final class TemplateSlugCollisionCheck implements AuditCheckInterface {

	public function name(): string {
		return 'template-slug-collision';
	}

	public function description(): string {
		return 'wp_template/wp_template_part slugs (e.g. header, footer) existing on more than one site; the main site\'s wins.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		// post_name is the template slug; post_title is its display
		// name. Keyed slug => per-site list of rows.
		$bySlug = array();
		foreach ( ( new TemplateInventory() )->collect( $source, $sites ) as $blogId => $rows ) {
			foreach ( $rows as $row ) {
				$key = $row['post_type'] . '|' . $row['post_name'];
				$bySlug[ $key ][ $blogId ][] = $row;
			}
		}

		$findings = array();
		foreach ( $bySlug as $key => $perSite ) {
			if ( count( $perSite ) < 2 ) {
				continue;
			}

			list( $postType, $slug ) = explode( '|', $key, 2 );
			$siteIds = array_keys( $perSite );
			$isMainWinner = $config->mainSite !== null && isset( $perSite[ $config->mainSite ] );

			$titles = array();
			foreach ( $perSite as $blogId => $rows ) {
				foreach ( $rows as $row ) {
					$titles[] = sprintf(
						'site %d: "%s" (%s%s)',
						$blogId,
						$row['post_title'],
						$row['post_status'],
						$blogId === $config->mainSite ? ', main' : ''
					);
				}
			}

			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'%s slug "%s" exists on %d sites (%s) -- %s. [%s]',
					$postType,
					$slug,
					count( $perSite ),
					implode( ', ', array_map( 'strval', $siteIds ) ),
					$isMainWinner
						? sprintf( 'main site %d wins', $config->mainSite )
						: 'no main site configured or main site lacks this slug; winner is undecided',
					implode( '; ', $titles )
				),
				array(
				'post_type' => $postType,
				'slug' => $slug,
				'blog_ids' => $siteIds,
				'main_site_wins' => $isMainWinner,
				)
			);
		}

		return $findings;
	}
}
