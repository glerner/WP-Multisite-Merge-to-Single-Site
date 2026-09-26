<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Pure planning logic for bin/template-screenshots.php: given a site's
 * template inventory and TemplateContext, decide which shot-scraper
 * shots to take, on which URL, and how to find each element.
 *
 * A shot is only planned when the template/part actually renders on a
 * reachable page: wp_template slugs are resolved through the same
 * hierarchy WordPress uses (show_on_front, page_for_posts, front-page >
 * page > index), and wp_template_part rows are shot on a page whose
 * resolved template includes them. Stale rows -- customized under a
 * theme that is no longer active -- never render, so they are reported
 * as retag candidates instead of being screenshotted.
 *
 * No DB or filesystem dependency, so it is fully unit-testable.
 *
 * @package MergeMultisite
 */
final class TemplateShotPlan {

	/**
	 * Map of wp_template_part slug => CSS selector group. Rendered
	 * template parts don't carry their slug in the markup, so only
	 * slugs with a conventional semantic element (or classic-theme
	 * fallback) can be screenshotted at all.
	 *
	 * @var array<string, string>
	 */
	private const PART_SELECTORS = array(
		'header'  => 'header, .site-header, #header',
		'footer'  => 'footer, .site-footer, #footer',
		'sidebar' => 'aside, #sidebar, .sidebar',
		'main'    => 'main, #main, .site-main, #content',
	);

	/**
	 * Map of wp_template slug => URL suffix for templates that render a
	 * dedicated reachable URL without any lookup: a guaranteed-missing
	 * path produces a real 404, and any ?s= query a real search page.
	 * (The '404' key is an int once PHP casts it.)
	 *
	 * @var array<int|string, string>
	 */
	private const SYNTHETIC_URL_TEMPLATES = array(
		'404'    => 'merge-multisite-template-probe/',
		'search' => '?s=merge-multisite-template-probe',
	);

	/**
	 * Milliseconds to wait after page load so webfonts and lazy media
	 * settle -- guards against captures taken mid-layout-shift.
	 */
	public const SETTLE_WAIT_MS = 600;

	/**
	 * Viewport height floor for element shots. WordPress pages normally
	 * scroll the document (element content outside the viewport still
	 * rasterizes), but a theme using an inner scroll container clips
	 * whatever is scrolled out of it -- the element must fit inside the
	 * scroller's height, so element shots get a tall viewport.
	 */
	public const ELEMENT_VIEWPORT_HEIGHT = 2400;

	/**
	 * The site's front URL, e.g. "https://example.com/".
	 */
	public function siteUrl( Site $site ): string {
		return 'https://' . $site->domain . $site->path;
	}

	/**
	 * The shot list for one site.
	 *
	 * @param array<int, array{post_name:string, post_type:string, post_status:string, theme:string}> $templateRows
	 *        Rows from TemplateInventory for this one site.
	 *
	 * @return array{shots: array<int, array{slug:string, url:string, selector:?string, javascript:?string}>,
	 *               skipped: array<string, string>,
	 *               stale: array<string, string>}
	 *         skipped: slug => why no shot was planned.
	 *         stale: slug => theme owning an inactive-theme row (retag
	 *         the row to the active stylesheet to reactivate it).
	 */
	public function shotsFor( Site $site, array $templateRows, TemplateContext $context ): array {
		$home = $this->siteUrl( $site );
		$skipped = array();
		$stale = array();

		// Classify rows: a wp_template/wp_template_part only renders when
		// its wp_theme term matches the active stylesheet AND it is
		// published. Rows tagged with a plugin ("woocommerce/woocommerce")
		// render only on that plugin's pages; rows tagged with any other
		// theme are leftovers that render nowhere.
		$templates = array();
		$pluginTemplates = array();
		$parts = array();
		foreach ( $templateRows as $row ) {
			$slug = (string) $row['post_name'];
			$theme = (string) $row['theme'];
			$isTemplate = $row['post_type'] === 'wp_template';

			if ( str_contains( $theme, '/' ) ) {
				if ( $isTemplate ) {
					$pluginTemplates[ $slug ] = $theme;
				} else {
					$skipped[ $slug ] ??= sprintf( 'plugin part (%s) -- no reliable selector', $theme );
				}
			} elseif ( $theme !== $context->stylesheet ) {
				$stale[ $slug ] = $theme !== '' ? $theme : 'unknown';
			} elseif ( $row['post_status'] !== 'publish' ) {
				$skipped[ $slug ] ??= sprintf( 'wp_template row is "%s" (not live)', $row['post_status'] );
			} elseif ( $isTemplate ) {
				$templates[ $slug ] = true;
			} else {
				$parts[ $slug ] = true;
			}
		}

		// Which template renders the front page (and the posts page):
		// the first slug that exists as an active-theme row or as a
		// templates/*.html file in the theme or its parent. File-backed
		// slugs join the shot candidates too -- a template rendered from
		// a theme file is still worth comparing, even with no DB row.
		$existing = array_fill_keys( $context->themeTemplateSlugs, true ) + $templates;
		$resolvedFront = $this->resolveFirst(
			$context->showOnFront === 'page' ? array( 'front-page', 'page', 'index' ) : array( 'home', 'index' ),
			$existing
		);
		$resolvedPosts = $this->resolveFirst( array( 'home', 'index' ), $existing );

		// Template shots. Skip reasons are only recorded for inventory
		// rows; file-only slugs that resolve nowhere are silent.
		// (Array keys are cast: a slug like '404' is an int key.)
		$shotUrlByTemplate = array();
		foreach ( array_keys( $existing ) as $slug ) {
			$url = $this->templateUrl( (string) $slug, $context, $resolvedFront, $resolvedPosts, $home );
			if ( $url === null ) {
				if ( isset( $templates[ $slug ] ) ) {
					$skipped[ $slug ] ??= $this->templateSkipReason( (string) $slug, $context, $resolvedFront );
				}
				continue;
			}
			$shotUrlByTemplate[ $slug ] = $url;
		}

		// Plugin-owned templates render only on the plugin's own pages,
		// resolved through templateUrls (e.g. the WooCommerce page-ID
		// options) -- never through the theme hierarchy.
		foreach ( $pluginTemplates as $slug => $theme ) {
			$url = $context->templateUrls[ $slug ] ?? null;
			if ( $url === null ) {
				$skipped[ $slug ] ??= sprintf( 'plugin template (%s) -- no resolvable page', $theme );
				continue;
			}
			$shotUrlByTemplate[ $slug ] = $url;
		}

		// Part shots. header/footer get a shot even with no row (classic
		// themes have none but still render a header/footer); other parts
		// need an active-theme row. Either way the shot goes to a page
		// whose resolved template includes the part when the usage map
		// says the front page does not.
		$partShots = array();
		foreach ( array( 'header', 'footer' ) as $slug ) {
			$partShots[] = array(
				'slug' => $slug,
				'url' => $this->partUrl( $slug, $context, $shotUrlByTemplate, $resolvedFront, $home ) ?? $home,
				'selector' => self::PART_SELECTORS[ $slug ],
				'javascript' => $this->overlayGuardJs( self::PART_SELECTORS[ $slug ] ),
			);
		}
		foreach ( array_keys( $parts ) as $slug ) {
			// header/footer rows already have their always-on shots.
			if ( $slug === 'header' || $slug === 'footer' ) {
				continue;
			}
			if ( isset( self::PART_SELECTORS[ $slug ] ) ) {
				$url = $this->partUrl( $slug, $context, $shotUrlByTemplate, $resolvedFront, $home );
				if ( $url === null ) {
					$skipped[ $slug ] ??= sprintf(
						'only included by %s -- none resolve to a shot URL',
						implode( ', ', $context->partUsage[ $slug ] ?? array() )
					);
					continue;
				}
				$partShots[] = array(
				'slug' => $slug,
				'url' => $url,
				'selector' => self::PART_SELECTORS[ $slug ],
				'javascript' => $this->overlayGuardJs( self::PART_SELECTORS[ $slug ] ),
				);
			} else {
				$including = $context->partUsage[ $slug ] ?? array();
				$skipped[ $slug ] ??= sprintf(
					'no reliable CSS selector for "%s" (block markup does not carry the part slug)%s',
					$slug,
					$including === array()
						? '; not included by any template'
						: '; included by ' . implode( ', ', $including )
				);
			}
		}

		return array(
			'shots' => array( ...$partShots, ...$this->fullPageShots( $shotUrlByTemplate ) ),
			'skipped' => $skipped,
			'stale' => $stale,
		);
	}

	/**
	 * The URL a wp_template slug renders on, or null when no reachable
	 * page resolves to it.
	 */
	private function templateUrl( string $slug, TemplateContext $context, ?string $resolvedFront, ?string $resolvedPosts, string $home ): ?string {
		if ( isset( self::SYNTHETIC_URL_TEMPLATES[ $slug ] ) ) {
			return $home . self::SYNTHETIC_URL_TEMPLATES[ $slug ];
		}

		return match ( $slug ) {
			'front-page' => $context->showOnFront === 'page' ? $home : null,
			'home'       => $context->showOnFront === 'posts' ? $home : $context->postsPageUrl,
			'index'      => $resolvedFront === 'index' ? $home
				: ( $context->showOnFront === 'page' && $resolvedPosts === 'index' ? $context->postsPageUrl : null ),
			default      => $context->templateUrls[ $slug ] ?? null,
		};
	}

	/**
	 * Why a template slug with an inventory row got no shot.
	 */
	private function templateSkipReason( string $slug, TemplateContext $context, ?string $resolvedFront ): string {
		return match ( $slug ) {
			'front-page' => 'front page shows posts (show_on_front=posts), so front-page never renders',
			'home'       => 'front page is a static page and no posts page is set (page_for_posts=0)',
			'index'      => sprintf( 'fallback template; reachable pages resolve to "%s" first', $resolvedFront ?? 'theme default' ),
			default      => 'no reachable page renders it',
		};
	}

	/**
	 * Full-page shots from the resolved template => URL map, in a
	 * stable slug order rather than iteration order.
	 *
	 * @param array<string, string> $shotUrlByTemplate
	 *
	 * @return array<int, array{slug:string, url:string, selector:null, javascript:null}>
	 */
	private function fullPageShots( array $shotUrlByTemplate ): array {
		$shots = array();
		foreach ( $shotUrlByTemplate as $slug => $url ) {
			$shots[] = array(
			'slug' => $slug,
			'url' => $url,
			'selector' => null,
			'javascript' => null,
			);
		}

		return $shots;
	}

	/**
	 * The first slug that exists as an active-theme wp_template row or
	 * a theme templates/*.html file, or null when none do.
	 *
	 * @param string[]            $candidates In resolution order.
	 * @param array<string, bool> $existing   slug => true.
	 */
	private function resolveFirst( array $candidates, array $existing ): ?string {
		foreach ( $candidates as $slug ) {
			if ( isset( $existing[ $slug ] ) ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * URL to shoot a wp_template_part on: the front page when its
	 * resolved template includes the part (or no usage data exists --
	 * classic themes still render a header/footer), otherwise the shot
	 * URL of the first template that includes it. Null when it is only
	 * included by templates with no reachable page.
	 *
	 * @param array<string, string> $shotUrlByTemplate
	 */
	private function partUrl( string $slug, TemplateContext $context, array $shotUrlByTemplate, ?string $resolvedFront, string $home ): ?string {
		$including = $context->partUsage[ $slug ] ?? array();
		if ( $including === array() ) {
			return $home;
		}
		if ( $resolvedFront !== null && in_array( $resolvedFront, $including, true ) ) {
			return $home;
		}
		foreach ( $including as $templateSlug ) {
			if ( isset( $shotUrlByTemplate[ $templateSlug ] ) ) {
				return $shotUrlByTemplate[ $templateSlug ];
			}
		}

		return null;
	}

	/**
	 * JavaScript run before an element shot: scroll the target into
	 * view, then hide position:fixed/sticky elements that overlap it.
	 * Floating headers, cookie bars, back-to-top buttons, and chat
	 * widgets would otherwise paint into the element's clip.
	 * visibility:hidden keeps layout (unlike display:none), and
	 * elements inside or containing the target are never hidden -- a
	 * sticky nav inside a header part belongs in the shot.
	 */
	public function overlayGuardJs( string $selector ): string {
		$jsSelector = json_encode( $selector );
		return <<<JS
(() => {
  const t = document.querySelector({$jsSelector});
  if (!t) return;
  t.scrollIntoView({ block: 'nearest' });
  const r = t.getBoundingClientRect();
  for (const el of document.querySelectorAll('body *')) {
    if (el.contains(t) || t.contains(el)) continue;
    const p = getComputedStyle(el).position;
    if (p !== 'fixed' && p !== 'sticky') continue;
    const er = el.getBoundingClientRect();
    if (er.bottom <= r.top || er.top >= r.bottom) continue;
    el.style.visibility = 'hidden';
  }
})();
JS;
	}

	/**
	 * Renders the shots for every site as shot-scraper multi YAML.
	 *
	 * @param array<int, array{slug:string, url:string, selector:?string, javascript:?string, output:string}> $entries
	 */
	public function toYaml( array $entries ): string {
		$yaml = '';
		foreach ( $entries as $entry ) {
			$yaml .= '- output: ' . $entry['output'] . "\n";
			$yaml .= '  url: ' . $entry['url'] . "\n";
			$yaml .= '  retina: true' . "\n";
			$yaml .= '  wait: ' . self::SETTLE_WAIT_MS . "\n";
			$yaml .= '  wait_for: \'document.fonts.status === "loaded"\'' . "\n";
			if ( $entry['selector'] !== null ) {
				$yaml .= '  selector: "' . str_replace( '"', '\\"', $entry['selector'] ) . '"' . "\n";
				$yaml .= '  height: ' . self::ELEMENT_VIEWPORT_HEIGHT . "\n";
				$yaml .= '  javascript: |' . "\n";
				foreach ( explode( "\n", (string) $entry['javascript'] ) as $jsLine ) {
					$yaml .= '    ' . $jsLine . "\n";
				}
			}
		}

		return $yaml;
	}
}
