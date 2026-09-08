<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects pages whose content is likely to need manual review after a
 * theme/page-builder migration: any page builder, slideshow/carousel
 * element, or "complex plugin" (ecommerce, LMS, etc.) footprint.
 *
 * These plugins store presentation-critical data that cannot be
 * converted generically into the destination theme's native blocks --
 * see PLAN.md "Future enhancement: per-builder content inventory
 * exporter". The audit's job is to produce a focused, actionable list
 * ("these pages need checking"), not to convert anything.
 *
 * @package MergeMultisite
 */
final class NeedsReviewDetector implements ContentDetectorInterface {

	public function category(): string {
		return 'needs_review';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		// Page builders (metasig + content signatures).
		if ( $post->hasMetaKey( '_elementor_data' ) || $post->hasMetaKey( '_elementor_edit_mode' ) ) {
			$found[] = 'Elementor';
		}
		if ( $post->metaValue( '_et_pb_use_builder' ) === 'on' || str_contains( $post->content, '[et_pb_section' ) ) {
			$found[] = 'Divi';
		}
		if ( $post->hasMetaKey( '_fl_builder_data' ) || $post->hasMetaKey( '_fl_builder_enabled' ) ) {
			$found[] = 'Beaver Builder';
		}
		if ( str_contains( $post->content, 'wp:tenweb' ) || $post->hasMetaKey( '_tenweb_builder' ) ) {
			$found[] = '10Web Builder';
		}

		// Slideshow / carousel plugins (shortcode/block signatures;
		// a best-effort known list -- ShortcodeDetector's raw list
		// catches anything not named here).
		if ( preg_match( '/\[(?:soliloquy|metaslider|rev_slider|responsive_slider|cycloneslider|tpslider|elegant_pricing_table|easy_slider)\b/i', $post->content ) ) {
			$found[] = 'Slideshow plugin';
		}

		// Complex plugins: ecommerce.
		if ( $post->postType === 'product' || preg_match( '/\[add_to_cart\b|\[woocommerce_|wp:woocommerce\//i', $post->content ) ) {
			$found[] = 'WooCommerce';
		}
		if ( $post->postType === 'download' || preg_match( '/\[downloads\b|\[purchase_link\b|wp:edd\//i', $post->content ) ) {
			$found[] = 'Easy Digital Downloads';
		}
		if ( $post->postType === 'sc_product' || preg_match( '/\[sc_product\b|wp:surecart\//i', $post->content ) ) {
			$found[] = 'SureCart';
		}

		// Complex plugins: LMS.
		if ( str_starts_with( $post->postType, 'sfwd-' ) || str_starts_with( $post->postType, 'course' ) || str_starts_with( $post->postType, 'lesson' ) || str_starts_with( $post->postType, 'llms_' ) ) {
			$found[] = 'LMS (LearnDash/LifterLMS)';
		}

		return array_values( array_unique( $found ) );
	}
}
