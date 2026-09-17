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
 * Object IDs are site-local (per-site auto-increment), so labels show
 * the object's NAME as well as its ID -- "category \"Hello\" (#4)" --
 * and for taxonomy items the post-merge canonical label too, e.g.
 * 'category "hello" (#4) -> "Hello"' when a case-variant gets folded
 * into a different canonical spelling.
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
				'post_type' => $this->objectLabel( $object !== '' ? $object : 'post', $objectId, $post->postTitles ),
				'taxonomy' => $this->taxonomyLabel( $object !== '' ? $object : 'term', $objectId, $post ),
				'post_type_archive' => 'archive: ' . $object,
				default => $type !== '' ? $type : 'unknown menu item type',
			},
		);
	}

	/**
	 * Renders a linked object as `type "Name" (#id)` when the name is
	 * known, `type #id` when it isn't (target outside the scanned set
	 * or already deleted).
	 *
	 * @param array<int, string> $names Site-local object ID => name.
	 */
	private function objectLabel( string $kind, string $objectId, array $names ): string {
		$name = $names[ (int) $objectId ] ?? null;

		return $name !== null
			? sprintf( '%s "%s" (#%s)', $kind, $name, $objectId )
			: sprintf( '%s #%s', $kind, $objectId );
	}

	/**
	 * Like objectLabel(), but appends the cross-site merge target when
	 * the term's name is a case-variant that folds into a different
	 * canonical label: `category "hello" (#4) -> "Hello"`.
	 */
	private function taxonomyLabel( string $taxonomy, string $objectId, ScannedPost $post ): string {
		$label = $this->objectLabel( $taxonomy, $objectId, $post->termNames );

		$name = $post->termNames[ (int) $objectId ] ?? null;
		if ( $name === null ) {
			return $label;
		}

		$canonical = $post->termMergeTargets[ $taxonomy . '|' . strtolower( trim( $name ) ) ] ?? null;

		return $canonical !== null && $canonical !== $name
			? sprintf( '%s -> "%s"', $label, $canonical )
			: $label;
	}
}
