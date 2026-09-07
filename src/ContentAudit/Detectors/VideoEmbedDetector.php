<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects video usage: core embed blocks/oEmbed URLs for YouTube/Vimeo,
 * and self-hosted `<video>` tags.
 *
 * @package MergeMultisite
 */
final class VideoEmbedDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'video';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();
		$content = $post->content;

		if ( preg_match( '/wp:core-embed\/youtube|youtube\.com\/watch|youtu\.be\//i', $content ) ) {
			$found[] = 'YouTube';
		}

		if ( preg_match( '/wp:core-embed\/vimeo|vimeo\.com\//i', $content ) ) {
			$found[] = 'Vimeo';
		}

		if ( preg_match( '/<video\b/i', $content ) ) {
			$found[] = 'Self-hosted <video>';
		}

		return $found;
	}
}
