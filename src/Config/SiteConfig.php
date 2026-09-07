<?php

declare(strict_types=1);

namespace MergeMultisite\Config;

/**
 * One entry from `config/sites.php`: a single subsite's inclusion
 * decision and optional per-site overrides.
 *
 * @package MergeMultisite
 */
final class SiteConfig {

	/**
	 * @param int         $blogId       The site's `blog_id` in the source network.
	 * @param string      $domain       The site's domain (or domain+path).
	 * @param bool        $include      Whether this site is included in the merge.
	 * @param string|null $categoryName Optional override for the destination
	 *                                  category name; defaults to the site's own title.
	 * @param string|null $categorySlug Optional override for the destination
	 *                                  category slug.
	 */
	public function __construct(
		public readonly int $blogId,
		public readonly string $domain,
		public readonly bool $include,
		public readonly ?string $categoryName = null,
		public readonly ?string $categorySlug = null,
	) {
	}

	/**
	 * @param array<string, mixed> $data One raw entry from sites.php.
	 *
	 * @throws ConfigException If required keys are missing.
	 */
	public static function fromArray( array $data ): self {
		foreach ( array( 'blog_id', 'domain' ) as $required ) {
			if ( ! array_key_exists( $required, $data ) ) {
				throw new ConfigException( sprintf( 'Site config entry is missing required key "%s".', $required ) );
			}
		}

		return new self(
			blogId: (int) $data['blog_id'],
			domain: (string) $data['domain'],
			include: (bool) ( $data['include'] ?? true ),
			categoryName: isset( $data['category_name'] ) ? (string) $data['category_name'] : null,
			categorySlug: isset( $data['category_slug'] ) ? (string) $data['category_slug'] : null,
		);
	}
}
