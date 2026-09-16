<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\SiteConfig;

/**
 * A resolved subsite: data read from the source network's `wp_blogs`
 * table, merged with this run's `sites.php` include/exclude decision
 * and any per-site category overrides.
 *
 * @package MergeMultisite
 */
final class Site {

	/**
	 * @param int         $blogId       The site's `blog_id`.
	 * @param string      $domain       The site's domain.
	 * @param string      $path         The site's path (e.g. "/" for subdomain installs).
	 * @param string      $title        The site's `blogname` option value.
	 * @param bool        $deleted      Whether `wp_blogs.deleted` is set.
	 * @param bool        $included     Whether this run includes the site in the merge.
	 * @param string|null $categoryName Destination category name to use (override or title).
	 * @param string|null $categorySlug Destination category slug to use (override or derived).
	 */
	public function __construct(
		public readonly int $blogId,
		public readonly string $domain,
		public readonly string $path,
		public readonly string $title,
		public readonly bool $deleted,
		public readonly bool $included,
		public readonly ?string $categoryName = null,
		public readonly ?string $categorySlug = null,
	) {
	}

	/**
	 * Merge raw `wp_blogs` row data with the site's optional config
	 * override, applying the "included by default unless explicitly
	 * excluded, or deleted" rule described in PLAN.md §4.2.
	 *
	 * @param array<string, mixed> $blogRow From `wp_blogs`.
	 * @param string               $title   From that site's `blogname` option.
	 */
	public static function fromBlogRow( array $blogRow, string $title, ?SiteConfig $override ): self {
		$blogId = (int) $blogRow['blog_id'];
		$deleted = ( (int) ( $blogRow['deleted'] ?? 0 ) ) === 1;

		$included = $override->include ?? true;
		if ( $deleted ) {
			$included = false;
		}

		return new self(
			blogId: $blogId,
			domain: (string) $blogRow['domain'],
			path: (string) ( $blogRow['path'] ?? '/' ),
			title: $title,
			deleted: $deleted,
			included: $included,
			categoryName: $override->categoryName ?? $title,
			categorySlug: $override?->categorySlug,
		);
	}
}
