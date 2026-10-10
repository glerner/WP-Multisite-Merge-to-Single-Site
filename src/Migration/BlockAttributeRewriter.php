<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Rewrites post-ID references embedded in Gutenberg block-comment
 * attributes (PLAN.md §7.5).
 *
 * Blocks like `wp:navigation` and `wp:block` store their target post
 * ID in the comment's JSON attributes —
 * `<!-- wp:navigation {"ref":123} /-->`. Post-migration that ID points
 * at a different post, so every whitelisted attribute is remapped via
 * IdMap. Attributes with no recorded mapping are left untouched (the
 * dangling ref is a migration-report concern, not a silent rewrite).
 *
 * Shared by PostMigrator (post_content) and MenuMigrator (which rewrites
 * `wp_navigation` posts and template parts containing navigation refs).
 *
 * @package MergeMultisite
 */
final class BlockAttributeRewriter {

	/**
	 * Block comment with an optional JSON attribute object:
	 * `<!-- wp:navigation {"ref":123} /-->` — captures the block name,
	 * the raw JSON text, and whether the block is self-closing.
	 */
	private const BLOCK_PATTERN = '/<!--\s*wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)\s*(\{.*?\})?\s*(\/)?\s*-->/s';

	/**
	 * Remap every whitelisted integer attribute in every block
	 * comment found in `$content`. Attribute values may be a single
	 * ID (`wp:image {"id":123}`) or a list of IDs (`wp:gallery
	 * {"ids":[1,2,3]}`); each is remapped via IdMap when a mapping
	 * exists, and left untouched otherwise.
	 *
	 * @param int                   $blogId        Source blog ID (IdMap key).
	 * @param IdMap                 $idMap         Recorded old→new post IDs.
	 * @param array<string, string> $refAttributes JSON attribute name =>
	 *                                             IdMap entity type. Defaults
	 *                                             to `ref` => `post`.
	 */
	public function rewrite( string $content, int $blogId, IdMap $idMap, array $refAttributes = array( 'ref' => 'post' ) ): string {
		$rewritten = preg_replace_callback(
			self::BLOCK_PATTERN,
			function ( array $matches ) use ( $blogId, $idMap, $refAttributes ): string {
				$blockName = $matches[1];
				$jsonText = $matches[2] ?? '';
				$selfClosing = ( $matches[3] ?? '' ) === '/';

				if ( $jsonText === '' ) {
					return $matches[0];
				}

				$attributes = json_decode( $jsonText, true );
				if ( ! is_array( $attributes ) ) {
					return $matches[0];
				}

				$changed = false;
				foreach ( $refAttributes as $attribute => $entityType ) {
					if ( ! isset( $attributes[ $attribute ] ) ) {
						continue;
					}

					if ( is_numeric( $attributes[ $attribute ] ) ) {
						$newId = $idMap->get( $entityType, $blogId, (int) $attributes[ $attribute ] );
						if ( $newId === null ) {
							continue;
						}

						$attributes[ $attribute ] = $newId;
						$changed = true;
						continue;
					}

					if ( is_array( $attributes[ $attribute ] ) ) {
						$remapped = array();
						$listChanged = false;
						foreach ( $attributes[ $attribute ] as $listIndex => $listValue ) {
							if ( is_numeric( $listValue ) ) {
								$newListId = $idMap->get( $entityType, $blogId, (int) $listValue );
								if ( $newListId !== null ) {
									$remapped[ $listIndex ] = $newListId;
									$listChanged = true;
									continue;
								}
							}

							$remapped[ $listIndex ] = $listValue;
						}

						if ( $listChanged ) {
							$attributes[ $attribute ] = $remapped;
							$changed = true;
						}
					}
				}

				if ( ! $changed ) {
					return $matches[0];
				}

				$json = json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

				return sprintf(
					'<!-- wp:%s %s%s-->',
					$blockName,
					$json,
					$selfClosing ? ' /' : ' '
				);
			},
			$content
		);

		return is_string( $rewritten ) ? $rewritten : $content;
	}
}
