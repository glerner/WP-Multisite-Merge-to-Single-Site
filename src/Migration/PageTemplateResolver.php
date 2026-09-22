<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * Works out which template a scanned page/post renders through, and
 * whether that template survives the merge -- the audit's answer to
 * "which pages need to be redone or at least evaluated, and what
 * template did they use".
 *
 * Resolution follows WordPress: an explicit `_wp_page_template` meta
 * wins; otherwise the hierarchy candidate for the post type (page ->
 * page, post -> single, product -> single-product then single, ...),
 * existing when an active-theme wp_template row or a templates/*.html
 * file (child theme or parent) provides it.
 *
 * Statuses, from least to most work:
 *   ''                     -- resolves to a stock theme file; nothing per-page to do
 *   'customized'           -- resolves to a site-editor row of the active theme (migrate or rebuild it)
 *   'custom-template'      -- page explicitly selects an existing block template
 *   'missing-template'     -- page selects a template the active theme doesn't have (already falls back)
 *   'stale-theme-template' -- page selects another theme's template (worked under the old theme)
 *   'stale-customization'  -- the resolved slug's only customization is a stale-theme row (retag to restore)
 *   'classic-template'     -- page selects a classic .php template file (rebuild in the new theme)
 *   'plugin-template'      -- page selects a plugin-owned template
 *   'no-template'          -- nothing renders this type except core's index fallback
 *   'classic-theme'        -- site runs a classic theme; its PHP templates are
 *                             invisible to this inventory and all need new-theme
 *                             equivalents (per-page detail is unknowable)
 *
 * @package MergeMultisite
 */
final class PageTemplateResolver {

	/**
	 * Post types that never render through the public template
	 * hierarchy (internals, plugin definition types, media).
	 *
	 * @var string[]
	 */
	private const NON_FRONTEND_TYPES = array(
		'revision',
		'nav_menu_item',
		'attachment',
		'wp_template',
		'wp_template_part',
		'wp_block',
		'wp_navigation',
		'wp_global_styles',
		'wp_font_face',
		'wp_font_family',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wpcf7_contact_form',
		'wpforms',
		'elementor_library',
		'e-loop-item',
		'acf-field-group',
		'acf-field',
		'wpcode',
		// Stored form submissions and builder library items -- data
		// containers, not pages.
		'flamingo_contact',
		'flamingo_inbound',
		'flamingo_outbound',
		'et_pb_layout',
		'mc4wp-form',
		'nf_sub',
		'frm_form',
		'frm_display',
	);

	/**
	 * @param array<int, TemplateContext> $contexts blog_id => context.
	 */
	public function __construct( private readonly array $contexts ) {
	}

	/**
	 * @return array{template: string, status: string}
	 */
	public function describe( ScannedPost $post ): array {
		$context = $this->contexts[ $post->blogId ] ?? null;
		if ( $context === null || in_array( $post->postType, self::NON_FRONTEND_TYPES, true ) ) {
			return array(
			'template' => '',
			'status' => '',
			);
		}

		$meta = $post->metaValue( '_wp_page_template' );
		if ( $meta !== null && $meta !== '' && $meta !== 'default' ) {
			return $this->explicitTemplate( $context, $meta );
		}

		return $this->hierarchyTemplate( $context, $post );
	}

	/**
	 * An explicit `_wp_page_template` selection: 'theme//slug' for block
	 * templates (plugin-owned when the theme part contains '/'), a
	 * '*.php' path for classic template files, or a bare slug.
	 *
	 * @return array{template: string, status: string}
	 */
	private function explicitTemplate( TemplateContext $context, string $meta ): array {
		if ( str_contains( $meta, '//' ) ) {
			list( $theme, $slug ) = explode( '//', $meta, 2 );
			if ( str_contains( $theme, '/' ) ) {
				return array(
				'template' => $meta,
				'status' => 'plugin-template',
				);
			}
			if ( $theme !== $context->stylesheet ) {
				return array(
				'template' => $meta,
				'status' => 'stale-theme-template',
				);
			}

			return array(
				'template' => $meta,
				'status' => $this->exists( $context, $slug ) ? 'custom-template' : 'missing-template',
			);
		}

		if ( str_ends_with( $meta, '.php' ) ) {
			return array(
			'template' => $meta,
			'status' => 'classic-template',
			);
		}

		return array(
			'template' => $meta,
			'status' => $this->exists( $context, $meta ) ? 'custom-template' : 'missing-template',
		);
	}

	/**
	 * No explicit selection: walk the hierarchy candidates in order.
	 * A stale-theme row on a MORE specific candidate than the resolved
	 * one is reported instead -- the page "used to use" that template
	 * and would again if the row were retagged to the active theme.
	 *
	 * @return array{template: string, status: string}
	 */
	private function hierarchyTemplate( TemplateContext $context, ScannedPost $post ): array {
		if ( ! $this->isBlockTheme( $context ) ) {
			return array(
				'template' => 'classic theme',
				'status' => 'classic-theme',
			);
		}

		$blockedByStale = null;
		foreach ( $this->candidatesFor( $context, $post ) as $slug ) {
			if ( $this->exists( $context, $slug ) ) {
				if ( $blockedByStale !== null ) {
					return $this->staleResult( $context, $blockedByStale );
				}
				if ( $this->isStale( $context, $slug ) ) {
					// Rendered by a theme file while a dormant
					// customization waits under an inactive theme.
					return $this->staleResult( $context, $slug );
				}

				return array(
					'template' => $slug,
					'status' => in_array( $slug, $context->activeTemplateSlugs, true ) ? 'customized' : '',
				);
			}
			if ( $blockedByStale === null && $this->isStale( $context, $slug ) ) {
				$blockedByStale = $slug;
			}
		}

		if ( $blockedByStale !== null ) {
			return $this->staleResult( $context, $blockedByStale );
		}

		return array(
		'template' => $this->candidatesFor( $context, $post )[0] ?? '',
		'status' => 'no-template',
		);
	}

	/**
	 * @return array{template: string, status: string}
	 */
	private function staleResult( TemplateContext $context, string $slug ): array {
		return array(
			'template' => sprintf( '%s//%s', $context->staleTemplateRows[ $slug ], $slug ),
			'status' => 'stale-customization',
		);
	}

	/**
	 * Hierarchy candidates for this post, most specific first: the
	 * static front page resolves front-page > page > index, the posts
	 * page home > index, then per post type.
	 *
	 * @return string[]
	 */
	private function candidatesFor( TemplateContext $context, ScannedPost $post ): array {
		if ( $post->postType === 'page' ) {
			if ( $post->postId === $context->pageOnFront && $context->pageOnFront > 0 ) {
				return array( 'front-page', 'page', 'index' );
			}
			if ( $post->postId === $context->pageForPosts && $context->pageForPosts > 0 ) {
				return array( 'home', 'index' );
			}

			return array( 'page' );
		}
		if ( $post->postType === 'post' ) {
			return array( 'single' );
		}

		return array( 'single-' . $post->postType, 'single' );
	}

	/**
	 * Whether a slug renders: an active-theme wp_template row or a
	 * templates/{slug}.html file in the theme (or its parent).
	 */
	private function exists( TemplateContext $context, string $slug ): bool {
		return in_array( $slug, $context->activeTemplateSlugs, true )
			|| in_array( $slug, $context->themeTemplateSlugs, true );
	}

	/**
	 * Whether a slug has a stale-theme row AND no live one -- a
	 * customization that would render again if retagged. When an
	 * active-theme row also exists the stale sibling is just a merge
	 * collision, not something a page is missing.
	 */
	private function isStale( TemplateContext $context, string $slug ): bool {
		return isset( $context->staleTemplateRows[ $slug ] )
			&& ! in_array( $slug, $context->activeTemplateSlugs, true );
	}

	/**
	 * Whether the site runs a block theme: a templates/index.html file
	 * in the active theme or its parent (WordPress's own criterion),
	 * or live customized wp_template rows. Classic themes render via
	 * PHP templates our inventory can't see, so hierarchy resolution
	 * reports 'classic theme' instead of guessing.
	 */
	private function isBlockTheme( TemplateContext $context ): bool {
		return in_array( 'index', $context->themeTemplateSlugs, true )
			|| $context->activeTemplateSlugs !== array();
	}
}
