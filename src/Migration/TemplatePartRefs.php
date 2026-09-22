<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Extracts wp_template_part slug references from block markup --
 * `<!-- wp:template-part {"slug":"header","theme":"x"} /-->` -- in
 * wp_template post_content or a theme's templates/*.html files.
 * Pure string parsing; shared by TemplateContextCollector (which feeds
 * it DB rows and theme files) and unit tests.
 *
 * @package MergeMultisite
 */
final class TemplatePartRefs {

	/**
	 * @return string[] Distinct referenced part slugs, in order of
	 *                  first appearance.
	 */
	public static function in( string $markup ): array {
		if ( preg_match_all( '/wp:template-part\s+\{[^}]*"slug"\s*:\s*"([^"]+)"/', $markup, $matches ) === 0 ) {
			return array();
		}

		return array_values( array_unique( $matches[1] ) );
	}
}
