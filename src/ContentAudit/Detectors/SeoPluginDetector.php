<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects which SEO plugin has metadata saved against a given post, by
 * its distinctive postmeta key prefix.
 *
 * @package MergeMultisite
 */
final class SeoPluginDetector implements ContentDetectorInterface {

	/**
	 * @var array<string, string>
	 */
	private const META_PREFIXES = array(
		'_yoast_wpseo_' => 'Yoast SEO',
		'_tsf_' => 'The SEO Framework',
		'_genesis_title' => 'The SEO Framework (legacy)',
		'rank_math_' => 'Rank Math',
		'_aioseo_' => 'All in One SEO',
		'_sq_' => 'Squirrly SEO',
	);

	/**
	 * META_PREFIXES after config extras.
	 *
	 * @var array<string, string>
	 */
	private readonly array $metaPrefixes;

	/**
	 * @param array<string, mixed> $extras Optional 'detector_extras'
	 *        block for this category (plugin-roles.php):
	 *        'meta_prefixes' => meta-key prefix => plugin label.
	 */
	public function __construct( array $extras = array() ) {
		$this->metaPrefixes = DetectorExtras::prefixMap( self::META_PREFIXES, $extras['meta_prefixes'] ?? null );
	}

	public function category(): string {
		return 'seo_plugin';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( $this->metaPrefixes as $prefix => $label ) {
			if ( $post->hasMetaKeyPrefixed( $prefix ) ) {
				$found[] = $label;
			}
		}

		return array_unique( $found );
	}
}
