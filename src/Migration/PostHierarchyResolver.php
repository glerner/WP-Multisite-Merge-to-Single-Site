<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Builds full hierarchical slug paths (`grandparent/parent/child`)
 * for one site's posts, from a `{post_id => post row}` map.
 *
 * Only a same-post-type parent nests in the path, mirroring how
 * WordPress permalinks treat hierarchical post types (pages and
 * hierarchical CPTs): a page's parent is always another page, while
 * an attachment's `post_parent` points at the post it was uploaded
 * to (a different type) and a revision's parent is the live post --
 * neither belongs in the URL path.
 *
 * Shared by PostScanner (accurate `original_url` on nested pages),
 * PostMigrator (two-pass `post_parent` resolution via IdMap), and
 * RedirectMapBuilder/UrlRewriter (exact hierarchical permalinks for
 * 301 targets and internal-link rewriting). PLAN.md §7.1.
 *
 * @package MergeMultisite
 */
final class PostHierarchyResolver {

	/**
	 * @param array<int, array{post_name:string, post_parent:int, post_type:string}> $posts
	 */
	public function __construct( private readonly array $posts ) {
	}

	/**
	 * Build a resolver from raw `wp_posts` rows.
	 *
	 * @param array<int, array{ID:mixed, post_name:mixed, post_parent:mixed, post_type:mixed}> $rows
	 */
	public static function fromRows( array $rows ): self {
		$posts = array();
		foreach ( $rows as $row ) {
			$posts[ (int) $row['ID'] ] = array(
				'post_name'   => (string) $row['post_name'],
				'post_parent' => (int) $row['post_parent'],
				'post_type'   => (string) $row['post_type'],
			);
		}

		return new self( $posts );
	}

	/**
	 * The hierarchical slug path for a post: `parent/child` for nested
	 * pages, the bare `post_name` for top-level or non-hierarchical
	 * posts. Missing parents (filtered out of the scan, or deleted
	 * rows) and parent cycles both stop the walk at what is known.
	 */
	public function slugPath( int $postId ): string {
		$segments = array();
		$seen = array();
		$current = $postId;

		while ( isset( $this->posts[ $current ] ) && ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;
			$post = $this->posts[ $current ];
			$segments[] = $post['post_name'];

			$parent = $post['post_parent'];
			if ( $parent <= 0
				|| ! isset( $this->posts[ $parent ] )
				|| $this->posts[ $parent ]['post_type'] !== $post['post_type']
			) {
				break;
			}

			$current = $parent;
		}

		return implode(
			'/',
			array_reverse( array_filter( $segments, static fn ( string $segment ): bool => $segment !== '' ) )
		);
	}
}
