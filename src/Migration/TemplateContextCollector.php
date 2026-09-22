<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Builds the per-site TemplateContext used by TemplateShotPlan and
 * PageTemplateResolver: reads the site's template-relevant options
 * (stylesheet/template, show_on_front, page_on_front/page_for_posts,
 * WooCommerce page IDs), resolves the post/option-derived URLs those
 * options point at, and scans the active theme's templates/*.html files
 * (child theme first, then its parent) for template slugs and
 * template-part references.
 *
 * URL building uses the same approximation as the rest of the tool:
 * site URL + post_name + '/', ignoring permalink structure and parent
 * page paths.
 *
 * @package MergeMultisite
 */
final class TemplateContextCollector {

	/**
	 * Map of wp_template slug => option holding the page ID that renders
	 * it (WooCommerce's page settings).
	 *
	 * @var array<string, string>
	 */
	private const OPTION_PAGE_TEMPLATES = array(
		'page-cart'       => 'woocommerce_cart_page_id',
		'page-checkout'   => 'woocommerce_checkout_page_id',
		'page-my-account' => 'woocommerce_myaccount_page_id',
		'archive-product' => 'woocommerce_shop_page_id',
	);

	/**
	 * Map of wp_template slug => post_type whose latest published post
	 * renders it. `single-{post_type}` slugs are also resolved
	 * generically.
	 *
	 * @var array<string, string>
	 */
	private const POST_TYPE_TEMPLATES = array(
		'single'          => 'post',
		'page'            => 'page',
		'single-product'  => 'product',
	);

	/**
	 * @param string $themesPath Absolute path to wp-content/themes.
	 */
	public function __construct( private readonly string $themesPath ) {
	}

	/**
	 * Derive the themes directory from a wp-content/uploads path (its
	 * sibling), matching PluginInventory::fromUploadsPath().
	 */
	public static function fromUploadsPath( string $uploadsPath ): self {
		return new self( rtrim( dirname( rtrim( $uploadsPath, '/' ) ), '/' ) . '/themes' );
	}

	/**
	 * @param array<int, array<string, mixed>> $templateRows TemplateInventory
	 *        rows for this one site (must carry post_name, post_type,
	 *        post_status, post_content, theme).
	 */
	public function collect( Connection $source, Site $site, array $templateRows ): TemplateContext {
		$optionsTable = $source->siteTable( 'options', $site->blogId );

		$optionNames = array_merge(
			array( 'stylesheet', 'template', 'show_on_front', 'page_on_front', 'page_for_posts' ),
			array_values( self::OPTION_PAGE_TEMPLATES )
		);
		$placeholders = array();
		$params = array();
		foreach ( $optionNames as $index => $optionName ) {
			$key = 'opt_' . $index;
			$placeholders[] = ':' . $key;
			$params[ $key ] = $optionName;
		}
		$options = array();
		foreach ( $source->fetchAll(
			sprintf(
				'SELECT option_name, option_value FROM %s WHERE option_name IN (%s)',
				$optionsTable,
				implode( ', ', $placeholders )
			),
			$params
		) as $row ) {
			$options[ (string) $row['option_name'] ] = (string) $row['option_value'];
		}

		$stylesheet     = $options['stylesheet'] ?? '';
		$templateOption = $options['template'] ?? $stylesheet;
		// WordPress resolves a child theme's parent from the child theme's
		// own `Template:` style.css header -- the `template` option is just
		// the recorded copy and can be stale after a manual theme switch
		// (e.g. stylesheet=website-tech while template still says
		// twentytwentythree). Trust the header; the option is only a
		// fallback when style.css itself is unreadable.
		$parent = $this->parentThemeFor( $stylesheet, $templateOption );
		$showOnFront = $options['show_on_front'] ?? 'posts';
		$pageOnFront = (int) ( $options['page_on_front'] ?? 0 );
		$pageForPosts = (int) ( $options['page_for_posts'] ?? 0 );

		$baseUrl = 'https://' . $site->domain . $site->path;
		$templateUrls = array();

		// Posts page: the URL `home`/`index` render when a static front
		// page is set.
		$postsPageUrl = $pageForPosts > 0
			? $this->urlForPostId( $source, $site->blogId, $pageForPosts, $baseUrl )
			: null;

		// WooCommerce page-ID options -> the URL of that page.
		foreach ( self::OPTION_PAGE_TEMPLATES as $slug => $optionName ) {
			$pageId = (int) ( $options[ $optionName ] ?? 0 );
			if ( $pageId > 0 ) {
				$url = $this->urlForPostId( $source, $site->blogId, $pageId, $baseUrl );
				if ( $url !== null ) {
					$templateUrls[ $slug ] = $url;
				}
			}
		}

		// Sample-post URLs for post-type-backed templates, including any
		// `single-{post_type}` slugs present in the site's rows.
		$postTypeTemplates = self::POST_TYPE_TEMPLATES;
		foreach ( $templateRows as $row ) {
			if ( $row['post_type'] === 'wp_template'
				&& preg_match( '/^single-(.+)$/', (string) $row['post_name'], $m ) === 1 ) {
				$postTypeTemplates[ (string) $row['post_name'] ] = $m[1];
			}
		}
		foreach ( $postTypeTemplates as $slug => $postType ) {
			if ( isset( $templateUrls[ $slug ] ) ) {
				continue;
			}
			$url = $this->samplePostUrl( $source, $site->blogId, $postType, $baseUrl, $pageOnFront, $pageForPosts );
			if ( $url !== null ) {
				$templateUrls[ $slug ] = $url;
			}
		}

		// Theme files: the child's own templates/*.html plus the parent's
		// (child themes inherit them). $themeDirs is ordered child-first
		// on purpose, and $templateMarkups uses ??= so the FIRST markup
		// seen for a slug wins -- a plain = would silently let the parent
		// overwrite the child, which is backwards (child files shadow
		// parent files in WP). File contents feed the part-usage map
		// alongside the DB rows' post_content.
		$themeTemplateSlugs = array();
		$themeDirs = array_filter( array( $stylesheet, $parent ) );
		$templateMarkups = array();
		foreach ( $themeDirs as $dir ) {
			$templateFiles = glob( $this->themesPath . '/' . $dir . '/templates/*.html' );
			foreach ( $templateFiles === false ? array() : $templateFiles as $file ) {
				$slug = basename( $file, '.html' );
				$themeTemplateSlugs[ $slug ] = true;
				$markup = file_get_contents( $file );
				if ( is_string( $markup ) ) {
					$templateMarkups[ $slug ] ??= $markup;
				}
			}
		}

		// Part usage: which templates (DB rows of the active theme, then
		// theme files) include each wp_template_part slug. Row ownership
		// is tracked as two maps -- a slug can have BOTH an active-theme
		// row and a stale one, so a single slug=>theme map can't say both.
		$partUsage = array();
		$activeTemplateSlugs = array();
		$staleTemplateRows = array();
		foreach ( $templateRows as $row ) {
			$slug = (string) $row['post_name'];
			$theme = (string) ( $row['theme'] ?? '' );
			if ( $row['post_type'] === 'wp_template_part' ) {
				continue;
			}
			if ( $theme === $stylesheet && $row['post_status'] === 'publish' ) {
				$activeTemplateSlugs[ $slug ] = true;
				$templateMarkups[ $slug ] = (string) ( $row['post_content'] ?? '' );
			} elseif ( $theme !== '' && ! str_contains( $theme, '/' ) ) {
				$staleTemplateRows[ $slug ] ??= $theme;
			}
		}
		foreach ( $templateMarkups as $templateSlug => $markup ) {
			foreach ( TemplatePartRefs::in( $markup ) as $partSlug ) {
				$partUsage[ $partSlug ][] = $templateSlug;
			}
		}

		return new TemplateContext(
			stylesheet: $stylesheet,
			parentStylesheet: $parent,
			showOnFront: $showOnFront,
			pageOnFront: $pageOnFront,
			pageForPosts: $pageForPosts,
			postsPageUrl: $postsPageUrl,
			themeTemplateSlugs: array_keys( $themeTemplateSlugs ),
			partUsage: $partUsage,
			templateUrls: $templateUrls,
			activeTemplateSlugs: array_keys( $activeTemplateSlugs ),
			staleTemplateRows: $staleTemplateRows,
			templateOption: $templateOption,
		);
	}

	/**
	 * Parent theme stylesheet for a child theme, parsed from the child's
	 * `Template:` style.css header the way WP_Theme does. Returns null
	 * for root themes (no header) and falls back to the `template` option
	 * only when style.css is missing.
	 */
	private function parentThemeFor( string $stylesheet, string $templateOption ): ?string {
		$styleCss = $this->themesPath . '/' . $stylesheet . '/style.css';
		if ( is_file( $styleCss ) ) {
			$contents = file_get_contents( $styleCss );
			$header   = is_string( $contents )
				&& preg_match( '/^\s*Template:\s*(\S+)\s*$/mi', $contents, $m ) === 1
				? $m[1]
				: '';
			$parent = $header;
		} else {
			$parent = $templateOption;
		}

		return $parent !== '' && $parent !== $stylesheet ? $parent : null;
	}

	/**
	 * URL of a post ID, or null when the ID doesn't resolve to a
	 * published post (deleted page, stale option).
	 */
	private function urlForPostId( Connection $source, int $blogId, int $postId, string $baseUrl ): ?string {
		$postsTable = $source->siteTable( 'posts', $blogId );
		$slug = $source->fetchScalar(
			"SELECT post_name FROM {$postsTable} WHERE ID = :id AND post_status = 'publish'",
			array( 'id' => $postId )
		);

		return is_string( $slug ) && $slug !== '' ? $baseUrl . $slug . '/' : null;
	}

	/**
	 * URL of the latest published post of a type -- the page a
	 * `single`-family template renders. For pages, prefer one that is
	 * not the front/posts page and uses the default template, so the
	 * shot actually exercises `page` rather than a page-override.
	 */
	private function samplePostUrl( Connection $source, int $blogId, string $postType, string $baseUrl, int $pageOnFront, int $pageForPosts ): ?string {
		$postsTable = $source->siteTable( 'posts', $blogId );
		$metaTable = $source->siteTable( 'postmeta', $blogId );

		$sql = "SELECT p.post_name FROM {$postsTable} p
			LEFT JOIN {$metaTable} m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template'
			WHERE p.post_type = :type AND p.post_status = 'publish'
			  AND p.ID <> :front AND p.ID <> :posts
			  AND (m.meta_value IS NULL OR m.meta_value = 'default')
			ORDER BY p.ID LIMIT 1";
		$slug = $source->fetchScalar(
			$sql,
			array(
			'type' => $postType,
			'front' => $pageOnFront,
			'posts' => $pageForPosts,
			)
		);

		if ( ! is_string( $slug ) || $slug === '' ) {
			$slug = $source->fetchScalar(
				"SELECT post_name FROM {$postsTable} WHERE post_type = :type AND post_status = 'publish' ORDER BY ID LIMIT 1",
				array( 'type' => $postType )
			);
		}

		return is_string( $slug ) && $slug !== '' ? $baseUrl . $slug . '/' : null;
	}
}
