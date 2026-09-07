<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects which page builder (if any) was used to build a post, since
 * Elementor/Divi/Beaver Builder don't represent their content as plain
 * Gutenberg block comments -- each has its own postmeta/content
 * signature.
 *
 * @package MergeMultisite
 */
final class PageBuilderDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'page_builder';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		if ( $post->hasMetaKey( '_elementor_data' ) || $post->hasMetaKey( '_elementor_edit_mode' ) ) {
			$found[] = 'Elementor';
		}

		if (
			$post->metaValue( '_et_pb_use_builder' ) === 'on'
			|| str_contains( $post->content, '[et_pb_section' )
		) {
			$found[] = 'Divi';
		}

		if ( $post->hasMetaKey( '_fl_builder_data' ) || $post->hasMetaKey( '_fl_builder_enabled' ) ) {
			$found[] = 'Beaver Builder';
		}

		if ( str_contains( $post->content, 'wp:tenweb' ) || $post->hasMetaKey( '_tenweb_builder' ) ) {
			$found[] = '10Web Builder';
		}

		return $found;
	}
}
