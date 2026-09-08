<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit;

/**
 * Extracts "ugly" raw data for the page-builder / complex-plugin
 * footprints found on a page, so a reviewer has concrete details to
 * rebuild from (slides' image IDs, settings, shortcode attributes)
 * without having to open the original site's editor.
 *
 * This is deliberately NOT pretty output -- it's a raw dump proving
 * the data is available. Truncated per entry so a single page can't
 * bloat the report.
 *
 * @package MergeMultisite
 */
final class RawContentExtractor {

	/**
	 * Max characters of raw data kept per meta key / content snippet.
	 */
	private const MAX_RAW_LENGTH = 600;

	/**
	 * @return array<string, string> keyed by plugin/label, value = raw excerpt.
	 */
	public function extract( ScannedPost $post, array $detectedLabels ): array {
		$raw = array();

		if ( in_array( 'Elementor', $detectedLabels, true ) ) {
			$elementorData = $post->metaValue( '_elementor_data' );
			if ( $elementorData !== null ) {
				$raw['Elementor _elementor_data'] = $this->truncate( $elementorData );
			}
		}

		if ( in_array( 'Divi', $detectedLabels, true ) ) {
			$sections = array();
			if ( preg_match_all( '/\[et_pb_[a-z_]+[^\]]*\]/i', $post->content, $m ) ) {
				$sections = array_slice( array_unique( $m[0] ), 0, 10 );
			}
			if ( $sections !== array() ) {
				$raw['Divi shortcode attrs'] = $this->truncate( implode( "\n", $sections ) );
			}
		}

		if ( in_array( 'Beaver Builder', $detectedLabels, true ) ) {
			$beaverData = $post->metaValue( '_fl_builder_data' );
			if ( $beaverData !== null ) {
				$raw['Beaver _fl_builder_data'] = $this->truncate( $beaverData );
			}
		}

		if ( in_array( 'WooCommerce', $detectedLabels, true ) ) {
			$raw['WooCommerce price'] = (string) ( $post->metaValue( '_price' ) ?? '' );
			$raw['WooCommerce sku'] = (string) ( $post->metaValue( '_sku' ) ?? '' );
			$raw['WooCommerce gallery'] = (string) ( $post->metaValue( '_product_image_gallery' ) ?? '' );
		}

		if ( in_array( 'Slideshow plugin', $detectedLabels, true ) ) {
			$shortcodes = array();
			if ( preg_match_all( '/\[(?:soliloquy|metaslider|rev_slider|responsive_slider|cycloneslider|tpslider)\b[^\]]*\]/i', $post->content, $m ) ) {
				$shortcodes = array_slice( array_unique( $m[0] ), 0, 10 );
			}
			if ( $shortcodes !== array() ) {
				$raw['Slideshow shortcodes'] = $this->truncate( implode( "\n", $shortcodes ) );
			}
		}

		// Generic fallback for anything detected but not specially
		// extracted above: the first 600 chars of raw post_content,
		// so the reviewer at least sees the markup the builder left.
		$knownKeys = array( 'Elementor _elementor_data', 'Divi shortcode attrs', 'Beaver _fl_builder_data', 'WooCommerce price', 'WooCommerce sku', 'WooCommerce gallery', 'Slideshow shortcodes' );
		$hasAny = array() !== array_intersect( $knownKeys, array_keys( $raw ) );
		if ( ! $hasAny && $post->content !== '' ) {
			$raw['raw post_content (first 600 chars)'] = $this->truncate( $post->content );
		}

		return $raw;
	}

	private function truncate( string $value ): string {
		if ( strlen( $value ) <= self::MAX_RAW_LENGTH ) {
			return $value;
		}

		return substr( $value, 0, self::MAX_RAW_LENGTH ) . '...[truncated]';
	}
}
