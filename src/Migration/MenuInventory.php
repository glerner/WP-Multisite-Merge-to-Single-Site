<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Per-site inventory of nav menus and their `nav_menu_item` posts
 * (PLAN.md §7.5).
 *
 * One site yields its `nav_menu` terms (the menus themselves) plus
 * every menu item row with the `_menu_item_*` postmeta set that
 * describes what it links to and where it sits in the hierarchy:
 *
 *  - `_menu_item_type`       -- custom | post_type | taxonomy | post_type_archive
 *  - `_menu_item_object`     -- the post type or taxonomy name
 *  - `_menu_item_object_id`  -- posts.ID or terms.term_id target
 *  - `_menu_item_url`        -- custom link target
 *  - `_menu_item_menu_item_parent` -- parent item post ID (hierarchy)
 *
 * Shared by MenuWidgetIntegrityCheck (broken-target auditing) and
 * MenuMigrator (recreating menus, items, and their term_relationships
 * on the destination with object IDs rewritten via IdMap).
 *
 * @package MergeMultisite
 */
final class MenuInventory {

	/**
	 * Gather one site's menus and menu items.
	 *
	 * Meta values come back as strings/null just as PDO returns them;
	 * `menu_term_taxonomy_id` is null for an item not assigned to any
	 * `nav_menu` term (orphaned items WP-CLI calls "unassigned").
	 *
	 * @return array{
	 *     menus: array<int, array{term_id:string, term_taxonomy_id:string, name:string, slug:string}>,
	 *     items: array<int, array{post_id:string, title:string, status:string, menu_order:string,
	 *                             menu_term_taxonomy_id:?string, item_type:?string, object:?string,
	 *                             object_id:?string, item_url:?string, item_parent:?string}>
	 * }
	 */
	public function collect( Connection $source, int $blogId ): array {
		$postsTable = $source->siteTable( 'posts', $blogId );
		$postMetaTable = $source->siteTable( 'postmeta', $blogId );
		$termsTable = $source->siteTable( 'terms', $blogId );
		$taxonomyTable = $source->siteTable( 'term_taxonomy', $blogId );
		$relationshipsTable = $source->siteTable( 'term_relationships', $blogId );

		$menus = $source->fetchAll(
			"SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug
             FROM {$termsTable} t
             INNER JOIN {$taxonomyTable} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'nav_menu'
             ORDER BY t.term_id"
		);

		// The correlated subquery returns the item's nav_menu
		// term_taxonomy_id without duplicating the row when the item
		// also relates to other taxonomies.
		$items = $source->fetchAll(
			"SELECT item.ID AS post_id, item.post_title AS title, item.post_status AS status,
			        item.menu_order,
			        (SELECT tt2.term_taxonomy_id
			           FROM {$relationshipsTable} tr2
			           INNER JOIN {$taxonomyTable} tt2
			              ON tt2.term_taxonomy_id = tr2.term_taxonomy_id AND tt2.taxonomy = 'nav_menu'
			          WHERE tr2.object_id = item.ID
			          LIMIT 1) AS menu_term_taxonomy_id,
			        typeMeta.meta_value     AS item_type,
			        objectMeta.meta_value   AS object,
			        objectIdMeta.meta_value AS object_id,
			        urlMeta.meta_value      AS item_url,
			        parentMeta.meta_value   AS item_parent
             FROM {$postsTable} item
             LEFT JOIN {$postMetaTable} typeMeta
                ON typeMeta.post_id = item.ID AND typeMeta.meta_key = '_menu_item_type'
             LEFT JOIN {$postMetaTable} objectMeta
                ON objectMeta.post_id = item.ID AND objectMeta.meta_key = '_menu_item_object'
             LEFT JOIN {$postMetaTable} objectIdMeta
                ON objectIdMeta.post_id = item.ID AND objectIdMeta.meta_key = '_menu_item_object_id'
             LEFT JOIN {$postMetaTable} urlMeta
                ON urlMeta.post_id = item.ID AND urlMeta.meta_key = '_menu_item_url'
             LEFT JOIN {$postMetaTable} parentMeta
                ON parentMeta.post_id = item.ID AND parentMeta.meta_key = '_menu_item_menu_item_parent'
             WHERE item.post_type = 'nav_menu_item'
             ORDER BY menu_term_taxonomy_id, item.menu_order, item.ID"
		);

		return array(
			'menus' => $menus,
			'items' => $items,
		);
	}
}
