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
	);

	/**
	 * Page builders that store their layout as PHP-SERIALIZED data in
	 * postmeta rather than JSON: map of plugin label => [meta key,
	 * module type to look for]. Beaver Builder's _fl_builder_data is a
	 * serialized tree of node objects whose module nodes carry
	 * ->settings->type (e.g. 'gallery', 'photos').
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const SERIALIZED_META_SIGNATURES = array(
		'Beaver Builder Gallery' => array( '_fl_builder_data', 'gallery' ),
	);

	/** @var array<string, string[]> */
	private readonly array $contentSignatures;

	/** @var array<string, array{0: string, 1: string}> */
	private readonly array $metaSignatures;

	/** @var array<string, array{0: string, 1: string}> */
	private readonly array $serializedMetaSignatures;

	/**
	 * @param array<string, mixed> $extras Optional 'detector_extras'
	 *        block for this category (plugin-roles.php):
	 *        'content_signatures' => label => patterns[] (appended),
	 *        'meta_signatures' => label => [meta_key, needle],
	 *        'serialized_meta_signatures' => label => [meta_key,
	 *        module_type] for PHP-serialized builder layouts
	 *        (both replaced per label).
	 */
	public function __construct( array $extras = array() ) {
		$this->contentSignatures = DetectorExtras::patternMap( self::CONTENT_SIGNATURES, $extras['content_signatures'] ?? null );
		$this->metaSignatures = DetectorExtras::tupleMap( self::META_SIGNATURES, $extras['meta_signatures'] ?? null );
		$this->serializedMetaSignatures = DetectorExtras::tupleMap( self::SERIALIZED_META_SIGNATURES, $extras['serialized_meta_signatures'] ?? null );
	}

	public function category(): string {
		return 'gallery';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( $this->contentSignatures as $label => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $post->content ) ) {
					$found[] = $label;
					break;
				}
			}
		}

		foreach ( $this->metaSignatures as $label => list( $metaKey, $needle ) ) {
			$value = $post->metaValue( $metaKey );
			if ( $value !== null && str_contains( $value, $needle ) ) {
				$found[] = $label;
			}
		}

		foreach ( $this->serializedMetaSignatures as $label => list( $metaKey, $moduleType ) ) {
			$value = $post->metaValue( $metaKey );
			if ( $value !== null && $this->serializedDataHasModuleType( $value, $moduleType ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}

	/**
	 * Whether a PHP-serialized builder layout contains a node whose
	 * 'type' equals $moduleType -- walks node objects and their
	 * ->settings so it matches both a module's own type and the module
	 * type nested in its settings.
	 */
	private function serializedDataHasModuleType( string $serialized, string $moduleType ): bool {
		// Only stdClass is materialized; other classes become
		// __PHP_Incomplete_Class, whose props get_object_vars() still
		// exposes -- so the walk below inspects them either way.
		$data = @unserialize( $serialized, array( 'allowed_classes' => array( 'stdClass' ) ) );
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return false;
		}

		$stack = array( $data );
		while ( $stack !== array() ) {
			$node = array_pop( $stack );
			$values = is_object( $node ) ? get_object_vars( $node ) : $node;

			if ( ( $values['type'] ?? null ) === $moduleType ) {
				return true;
			}

			foreach ( $values as $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					$stack[] = $value;
				}
			}
		}

		return false;
	}
}
