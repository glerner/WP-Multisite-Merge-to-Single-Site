<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit;

/**
 * One post/page/CPT entry being scanned by `site-audit.php`, along with
 * the postmeta a detector might need to consult.
 *
 * @package MergeMultisite
 */
final class ScannedPost {

	/**
	 * @param string                  $content    Raw `post_content`.
	 * @param array<string, string[]> $meta       All postmeta for this post, keyed by meta_key.
	 */
	public function __construct(
		public readonly int $blogId,
		public readonly int $postId,
		public readonly string $postType,
		public readonly string $postStatus,
		public readonly string $slug,
		public readonly string $content,
		public readonly array $meta,
	) {
	}

	public function metaValue( string $key ): ?string {
		return $this->meta[ $key ][0] ?? null;
	}

	public function hasMetaKey( string $key ): bool {
		return array_key_exists( $key, $this->meta );
	}

	/**
	 * Whether any configured meta key exists whose name starts with the
	 * given prefix (useful for plugin families like `_yoast_wpseo_*`).
	 */
	public function hasMetaKeyPrefixed( string $prefix ): bool {
		foreach ( array_keys( $this->meta ) as $key ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
