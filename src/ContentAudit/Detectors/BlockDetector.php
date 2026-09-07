<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Detects every non-core Gutenberg block type used in a post's
 * content, by parsing `<!-- wp:namespace/block-name -->` comments.
 * Core blocks (the bare `core/*` namespace) are ignored by default,
 * since the goal is to surface *plugin*-provided blocks.
 *
 * @package MergeMultisite
 */
final class BlockDetector implements ContentDetectorInterface {

	/**
	 * @param bool $includeCoreBlocks Set true to also report core/* blocks.
	 */
	public function __construct( private readonly bool $includeCoreBlocks = false ) {
	}

	public function category(): string {
		return 'blocks';
	}

	public function detect( ScannedPost $post ): array {
		// Gutenberg block comments omit the "core/" namespace for core
		// blocks (e.g. "<!-- wp:paragraph -->"), but always include it
		// for plugin-provided blocks (e.g. "<!-- wp:sureforms/form-selector -->").
		if ( ! preg_match_all( '/<!--\s*wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)/i', $post->content, $matches ) ) {
			return array();
		}

		$blocks = array_unique(
			array_map(
				static fn ( string $block ): string => str_contains( $block, '/' ) ? $block : 'core/' . $block,
				$matches[1]
			)
		);

		if ( ! $this->includeCoreBlocks ) {
			$blocks = array_values(
				array_filter(
					$blocks,
					static fn ( string $block ): bool => ! str_starts_with( $block, 'core/' )
				)
			);
		}

		sort( $blocks );

		return $blocks;
	}
}
