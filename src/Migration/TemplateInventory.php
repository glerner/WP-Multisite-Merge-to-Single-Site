<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Per-site inventory of wp_template / wp_template_part posts (block-theme
 * templates; headers and footers live in wp_template_part). Shared by
 * TemplateSlugCollisionCheck (which reports cross-site slug collisions)
 * and bin/template-screenshots.php (which screenshots each site's
 * rendered parts).
 *
 * @package MergeMultisite
 */
final class TemplateInventory {

	/**
	 * Fetches every non-trashed wp_template/wp_template_part row per site,
	 * each carrying its `theme` -- the wp_theme taxonomy term naming the
	 * theme ("website-tech") or plugin ("woocommerce/woocommerce") that
	 * owns the row -- and its post_content (TemplateContextCollector
	 * parses template-part references out of it).
	 *
	 * @param Site[] $sites
	 *
	 * @return array<int, array<int, array{ID:string, post_name:string, post_title:string, post_type:string, post_status:string, post_content:string, theme:string}>>
	 *         Rows keyed by blog_id, in site order.
	 */
	public function collect( Connection $source, array $sites ): array {
		$bySite = array();
		foreach ( $sites as $site ) {
			$postsTable = $source->siteTable( 'posts', $site->blogId );
			$relTable = $source->siteTable( 'term_relationships', $site->blogId );
			$taxTable = $source->siteTable( 'term_taxonomy', $site->blogId );
			$termsTable = $source->siteTable( 'terms', $site->blogId );
			$bySite[ $site->blogId ] = $source->fetchAll(
				"SELECT p.ID, p.post_name, p.post_title, p.post_type, p.post_status, p.post_content,
					(SELECT t.name FROM {$relTable} r
					 JOIN {$taxTable} x ON r.term_taxonomy_id = x.term_taxonomy_id
					 JOIN {$termsTable} t ON x.term_id = t.term_id
					 WHERE r.object_id = p.ID AND x.taxonomy = 'wp_theme' LIMIT 1) AS theme
                 FROM {$postsTable} p
                 WHERE p.post_type IN ('wp_template', 'wp_template_part')
                   AND p.post_status NOT IN ('trash', 'auto-draft')"
			);
		}

		return $bySite;
	}
}
