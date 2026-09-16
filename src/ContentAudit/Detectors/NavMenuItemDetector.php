<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Reports what each nav_menu_item links TO, so the spreadsheet shows
 * the menu structure rather than rows of empty columns: a page target
 * by ID, a taxonomy term, a custom URL, or a post-type archive.
 *
 * Menu items carry almost nothing in post_content -- the link target
 * lives in the _menu_item_* postmeta set (_menu_item_type,
 * _menu_item_object, _menu_item_object_id, _menu_item_url). Post-merge
 * every post_type/taxonomy link needs its object_id remapped; custom
 * URLs need the domain swapped. This detector exists to make that
 * remapping work visible per item.
 *
 * @package MergeMultisite
 */
final class NavMenuItemDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'nav_menu';
	}

	public function detect( ScannedPost $post ): array {
		if ( $post->postType !== 'nav_menu_item' ) {
			return array();
		}

		$type     = (string) ( $post->metaValue( '_menu_item_type' ) ?? '' );
		$object   = (string) ( $post->metaValue( '_menu_item_object' ) ?? '' );
		$objectId = (string) ( $post->metaValue( '_menu_item_object_id' ) ?? '' );
		$url      = (string) ( $post->metaValue( '_menu_item_url' ) ?? '' );

		return array(
			match ( $type ) {
				'custom' => 'custom: ' . $url,
				'post_type' => sprintf( '%s #%s', $object !== '' ? $object : 'post', $objectId ),
				'taxonomy' => sprintf( '%s #%s', $object !== '' ? $object : 'term', $objectId ),
				'post_type_archive' => 'archive: ' . $object,
				default => $type !== '' ? $type : 'unknown menu item type',
			},
		);
	}
}
