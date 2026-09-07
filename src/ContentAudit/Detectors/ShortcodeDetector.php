<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects every shortcode tag name used in a post's content, the same
 * way BlockDetector surfaces every non-core block type.
 *
 * This exists specifically to catch plugins we *haven't* written a
 * specific detector for yet: FormPluginDetector/GalleryDetector/etc.
 * only recognize signatures we already know about, but this detector
 * lists every `[shortcode_name ...]` tag found, known or not, so
 * running `site-audit.php` against real data will surface anything
 * unexpected rather than silently missing it. Overlap with the more
 * specific detectors (e.g. "gallery" also showing up here) is
 * expected and useful -- it lets you cross-check the specific
 * detectors' interpretation against the raw shortcode list.
 *
 * @package MergeMultisite
 */
final class ShortcodeDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'shortcodes';
	}

	public function detect( ScannedPost $post ): array {
		// Matches the tag name right after "[", but only for opening/
		// self-closing tags (the next character is whitespace, "]", or
		// "/"). Closing tags like "[/gallery]" start with "/" right
		// after "[", so they never match the leading [a-zA-Z] and are
		// correctly excluded without special-casing them.
		if ( ! preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_-]*)(?=[\s\/\]])/', $post->content, $matches ) ) {
			return array();
		}

		$shortcodes = array_unique( $matches[1] );
		sort( $shortcodes );

		return $shortcodes;
	}
}
