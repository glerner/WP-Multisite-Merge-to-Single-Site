<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\TemplateContext;
use MergeMultisite\Migration\TemplateShotPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( TemplateShotPlan::class )]
final class TemplateShotPlanTest extends TestCase {

	private function site( int $blogId = 20, string $domain = 'example.com' ): Site {
		return new Site(
			blogId: $blogId,
			domain: $domain,
			path: '/',
			title: 'Example',
			deleted: false,
			included: true
		);
	}

	/**
	 * A context with sensible defaults; override per test.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function context( array $overrides = array() ): TemplateContext {
		$defaults = array(
			'stylesheet' => 'mytheme',
			'parentStylesheet' => null,
			'showOnFront' => 'posts',
			'pageOnFront' => 0,
			'pageForPosts' => 0,
			'postsPageUrl' => null,
			'themeTemplateSlugs' => array(),
			'partUsage' => array(),
			'templateUrls' => array(),
			'activeTemplateSlugs' => array(),
			'staleTemplateRows' => array(),
		);

		return new TemplateContext( ...array_merge( $defaults, $overrides ) );
	}

	/**
	 * @param array<string, string> $overrides
	 *
	 * @return array<string, string>
	 */
	private function row( string $slug, string $type = 'wp_template', array $overrides = array() ): array {
		return array_merge(
			array(
				'post_name' => $slug,
				'post_type' => $type,
				'post_status' => 'publish',
				'post_content' => '',
				'theme' => 'mytheme',
			),
			$overrides
		);
	}

	public function testSiteUrlJoinsDomainAndPath(): void {
		$plan = new TemplateShotPlan();

		self::assertSame( 'https://example.com/', $plan->siteUrl( $this->site() ) );
	}

	public function testHeaderAndFooterShotsExistEvenWithNoTemplates(): void {
		$plan = new TemplateShotPlan();

		// Classic themes have no wp_template rows at all, but still
		// have a header and footer worth comparing.
		$result = $plan->shotsFor( $this->site(), array(), $this->context() );

		$slugs = array_column( $result['shots'], 'slug' );
		self::assertSame( array( 'header', 'footer' ), $slugs );
		self::assertSame( array(), $result['skipped'] );
		self::assertSame( array(), $result['stale'] );
	}

	public function testMappedPartIsShotOnTemplateThatIncludesIt(): void {
		$plan = new TemplateShotPlan();

		// 'sidebar' is only included by 'single' -- the shot must go to
		// the single-post URL, not the front page.
		$result = $plan->shotsFor(
			$this->site(),
			array(
				$this->row( 'sidebar', 'wp_template_part' ),
				$this->row( 'single' ),
			),
			$this->context(
				array(
					'partUsage' => array( 'sidebar' => array( 'single' ) ),
					'templateUrls' => array( 'single' => 'https://example.com/hello-world/' ),
					'activeTemplateSlugs' => array( 'single' ),
				)
			)
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/hello-world/', $bySlug['sidebar']['url'] );
		self::assertSame( 'aside, #sidebar, .sidebar', $bySlug['sidebar']['selector'] );
	}

	public function testPartWithoutSelectorIsSkippedWithReason(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'post-meta', 'wp_template_part' ) ),
			$this->context( array( 'partUsage' => array( 'post-meta' => array( 'single' ) ) ) )
		);

		self::assertArrayHasKey( 'post-meta', $result['skipped'] );
		self::assertStringContainsString( 'no reliable CSS selector', $result['skipped']['post-meta'] );
		self::assertStringContainsString( 'single', $result['skipped']['post-meta'] );
	}

	public function testHomeShootsPostsPageWhenStaticFrontIsSet(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'home' ) ),
			$this->context(
				array(
					'showOnFront' => 'page',
					'pageForPosts' => 40,
					'postsPageUrl' => 'https://example.com/blog/',
					'themeTemplateSlugs' => array( 'page' ),
				)
			)
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/blog/', $bySlug['home']['url'] );
	}

	public function testHomeIsSkippedWhenNoPostsPageExists(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'home' ) ),
			$this->context(
				array(
					'showOnFront' => 'page',
					'pageOnFront' => 12,
					'themeTemplateSlugs' => array( 'page' ),
				)
			)
		);

		self::assertArrayHasKey( 'home', $result['skipped'] );
		self::assertStringContainsString( 'page_for_posts=0', $result['skipped']['home'] );
	}

	public function testIndexIsSkippedWhenShadowedByMoreSpecificTemplate(): void {
		$plan = new TemplateShotPlan();

		// 'page' exists as a theme file, so '/' resolves to page, and
		// 'index' can only be shot where it actually renders.
		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'index' ) ),
			$this->context(
				array(
					'showOnFront' => 'page',
					'themeTemplateSlugs' => array( 'page', 'index' ),
				)
			)
		);

		self::assertArrayHasKey( 'index', $result['skipped'] );
		self::assertStringContainsString( 'page', $result['skipped']['index'] );
	}

	public function testFrontPageSkippedWhenFrontShowsPosts(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'front-page' ) ),
			$this->context( array( 'showOnFront' => 'posts' ) )
		);

		self::assertArrayHasKey( 'front-page', $result['skipped'] );
		self::assertStringContainsString( 'show_on_front=posts', $result['skipped']['front-page'] );
	}

	public function test404GetsSyntheticUrl(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( '404' ) ),
			$this->context()
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/merge-multisite-template-probe/', $bySlug['404']['url'] );
	}

	public function testSingleTemplateUsesResolvedSampleUrl(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'single' ) ),
			$this->context( array( 'templateUrls' => array( 'single' => 'https://example.com/hello-world/' ) ) )
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/hello-world/', $bySlug['single']['url'] );
	}

	public function testStaleThemeRowIsReportedNotShot(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'page', 'wp_template', array( 'theme' => 'twentytwentythree' ) ) ),
			$this->context()
		);

		self::assertSame( array( 'page' => 'twentytwentythree' ), $result['stale'] );
		self::assertSame( array( 'header', 'footer' ), array_column( $result['shots'], 'slug' ) );
	}

	public function testPluginTemplateShootsResolvablePage(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array(
				$this->row( 'page-cart', 'wp_template', array( 'theme' => 'woocommerce/woocommerce' ) ),
				$this->row( 'page-checkout', 'wp_template', array( 'theme' => 'woocommerce/woocommerce' ) ),
			),
			$this->context( array( 'templateUrls' => array( 'page-cart' => 'https://example.com/cart/' ) ) )
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/cart/', $bySlug['page-cart']['url'] );
		self::assertArrayHasKey( 'page-checkout', $result['skipped'] );
		self::assertStringContainsString( 'woocommerce/woocommerce', $result['skipped']['page-checkout'] );
	}

	public function testDraftRowIsSkippedNotStale(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'page', 'wp_template', array( 'post_status' => 'draft' ) ) ),
			$this->context()
		);

		self::assertArrayHasKey( 'page', $result['skipped'] );
		self::assertStringContainsString( 'draft', $result['skipped']['page'] );
		self::assertSame( array(), $result['stale'] );
	}

	public function testThemeFileTemplateGetsShotWithoutRow(): void {
		$plan = new TemplateShotPlan();

		// 'page' exists only as a theme file -- still worth a shot.
		$result = $plan->shotsFor(
			$this->site(),
			array(),
			$this->context(
				array(
					'themeTemplateSlugs' => array( 'page' ),
					'templateUrls' => array( 'page' => 'https://example.com/about/' ),
				)
			)
		);

		$bySlug = array_column( $result['shots'], null, 'slug' );
		self::assertSame( 'https://example.com/about/', $bySlug['page']['url'] );
	}

	public function testPartIncludedByUnresolvableTemplateIsSkipped(): void {
		$plan = new TemplateShotPlan();

		$result = $plan->shotsFor(
			$this->site(),
			array( $this->row( 'sidebar', 'wp_template_part' ) ),
			$this->context( array( 'partUsage' => array( 'sidebar' => array( 'archive' ) ) ) )
		);

		self::assertArrayHasKey( 'sidebar', $result['skipped'] );
		self::assertStringContainsString( 'archive', $result['skipped']['sidebar'] );
	}

	public function testToYamlEmitsExpectedEntries(): void {
		$plan = new TemplateShotPlan();

		$yaml = $plan->toYaml(
			array(
				array(
					'slug' => 'header',
					'url' => 'https://example.com/',
					'selector' => 'header, .site-header',
					'output' => '/out/20-header.png',
				),
				array(
					'slug' => 'index',
					'url' => 'https://example.com/',
					'selector' => null,
					'output' => '/out/20-index.png',
				),
			)
		);

		self::assertSame(
			"- output: /out/20-header.png\n"
			. "  url: https://example.com/\n"
			. "  selector: \"header, .site-header\"\n"
			. "- output: /out/20-index.png\n"
			. "  url: https://example.com/\n",
			$yaml
		);
	}
}
