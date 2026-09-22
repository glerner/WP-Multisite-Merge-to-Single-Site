<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Everything the pure planners need to know about one site's template
 * environment: which theme is active (and its parent), which template
 * slugs exist as theme files, which templates include which parts, and
 * which real front-end URLs render each template.
 *
 * Built by TemplateContextCollector (DB + filesystem); consumed by
 * TemplateShotPlan (screenshots) and PageTemplateResolver (audit
 * columns). Value object only -- no logic beyond the shape.
 *
 * @package MergeMultisite
 */
final class TemplateContext {

	/**
	 * @param string                  $stylesheet          Active theme (the `stylesheet` option).
	 * @param string|null             $parentStylesheet    Parent theme when $stylesheet is a child
	 *                                                     (the theme's `Template:` style.css header;
	 *                                                     the `template` option is only a fallback),
	 *                                                     else null.
	 * @param string                  $showOnFront         'posts' or 'page'.
	 * @param int                     $pageOnFront         Static front page ID (0 = none).
	 * @param int                     $pageForPosts        Posts page ID (0 = none).
	 * @param string|null             $postsPageUrl        Resolved URL of the posts page.
	 * @param string[]                $themeTemplateSlugs  Slugs with a templates/{slug}.html file in
	 *                                                     the active theme or its parent.
	 * @param array<string, string[]> $partUsage           wp_template_part slug => slugs of the
	 *                                                     templates that include it (active-theme
	 *                                                     rows and theme files).
	 * @param array<string, string>   $templateUrls        wp_template slug => a front-end URL that
	 *                                                     renders it (sample posts, WooCommerce
	 *                                                     page-ID options, posts page).
	 * @param string[]                $activeTemplateSlugs wp_template slugs with a published row
	 *                                                     tagged with the active stylesheet.
	 * @param array<string, string>   $staleTemplateRows   wp_template slug => owning theme for rows
	 *                                                     tagged with an inactive (non-plugin) theme.
	 * @param string                  $templateOption      Raw `template` option value, kept so callers
	 *                                                     can flag when it disagrees with the theme's
	 *                                                     own `Template:` header (stale option after
	 *                                                     a theme switch).
	 */
	public function __construct(
		public readonly string $stylesheet,
		public readonly ?string $parentStylesheet,
		public readonly string $showOnFront,
		public readonly int $pageOnFront,
		public readonly int $pageForPosts,
		public readonly ?string $postsPageUrl,
		public readonly array $themeTemplateSlugs,
		public readonly array $partUsage,
		public readonly array $templateUrls,
		public readonly array $activeTemplateSlugs,
		public readonly array $staleTemplateRows,
		public readonly string $templateOption = '',
	) {
	}
}
