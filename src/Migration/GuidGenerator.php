<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Regenerates post `guid` values for the destination (PLAN.md §7.1):
 * a GUID should reflect the site the post lives on, not the source
 * site's URL or ID space. Shared by PostMigrator and MediaMigrator so
 * every migrated post type gets a consistent form.
 *
 * @package MergeMultisite
 */
final class GuidGenerator {

	/**
	 * `https://destination.example/?p=123` — WordPress's default GUID
	 * form for posts, pointing at the post's new destination ID.
	 */
	public static function forPost( string $destinationUrl, int $newPostId ): string {
		return rtrim( $destinationUrl, '/' ) . '/?p=' . $newPostId;
	}

	/**
	 * The attachment GUID form WordPress core uses: the public URL of
	 * the attachment's file on the destination
	 * (`{base}/wp-content/uploads/{relative}`).
	 */
	public static function forAttachment( string $destinationUrl, string $relativePath ): string {
		return rtrim( $destinationUrl, '/' ) . '/wp-content/uploads/' . ltrim( $relativePath, '/' );
	}
}
