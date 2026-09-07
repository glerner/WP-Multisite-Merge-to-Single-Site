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
	);

	public function category(): string {
		return 'seo_plugin';
	}

	public function detect( ScannedPost $post ): array {
		$found = array();

		foreach ( self::META_PREFIXES as $prefix => $label ) {
			if ( $post->hasMetaKeyPrefixed( $prefix ) ) {
				$found[] = $label;
			}
		}

		return array_unique( $found );
	}
}
