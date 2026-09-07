<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects photo gallery usage: the core Gallery block/shortcode, a
 * dedicated gallery plugin, or a page builder's own gallery element
 * (Elementor, Divi, Beaver Builder, Kadence Blocks).
 *
 * As with FormPluginDetector, this is a best-effort starting list --
 * see ShortcodeDetector for a catch-all raw shortcode list to spot
 * anything not covered here yet.
 *
 * @package MergeMultisite
 */
final class GalleryDetector implements ContentDetectorInterface {

	/**
	 * Map of plugin label => list of regex patterns matched against
	 * `post_content`.
	 *
	 * @var array<string, string[]>
	 */
	private const CONTENT_SIGNATURES = array(
		'Core Gallery' => array( '\[gallery\b', 'wp:gallery\b', 'wp:core\/gallery\b' ),
		'Envira Gallery' => array( '\[envira-gallery\b', 'wp:envira\/' ),
		'NextGEN Gallery' => array( '\[nggallery\b', 'wp:nextgen\/' ),
		'Divi Gallery' => array( '\[et_pb_gallery\b' ),
		'Kadence Gallery' => array( 'wp:kadence\/advancedgallery\b', 'wp:kadence\/gallery\b' ),
	);

	/**
	 * Page builders that store their layout as JSON in a single
	 * postmeta value: map of plugin label => [meta key, needle].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const META_SIGNATURES = array(
		'Elementor Gallery' => array( '_elementor_data', '"widgetType":"gallery"' ),
		'Elementor Image Gallery' => array( '_elementor_data', '"widgetType":"image-gallery"' ),
		'Beaver Builder Gallery' => array( '_fl_builder_data', '"type":"gallery"' ),
	);

	public function category(): string {
		return 'gallery';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( self::CONTENT_SIGNATURES as $label => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $post->content ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		foreach ( self::META_SIGNATURES as $label => list( $metaKey, $needle ) ) {
			$value = $post->metaValue( $metaKey );
			if ( $value !== null && str_contains( $value, $needle ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}
}
