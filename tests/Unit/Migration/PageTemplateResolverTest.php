<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\ContentAudit\ScannedPost;
use MergeMultisite\Migration\PageTemplateResolver;
use MergeMultisite\Migration\TemplateContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PageTemplateResolver::class )]
final class PageTemplateResolverTest extends TestCase {

	/**
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

	private function post( string $postType = 'page', ?string $pageTemplate = null, int $blogId = 1, int $postId = 10 ): ScannedPost {
		return new ScannedPost(
			blogId: $blogId,
			postId: $postId,
			postType: $postType,
			postStatus: 'publish',
			slug: 'a-page',
			postTitle: 'A page',
			content: '',
			meta: $pageTemplate === null ? array() : array( '_wp_page_template' => array( $pageTemplate ) ),
		);
	}

	private function resolver( TemplateContext $context, int $blogId = 1 ): PageTemplateResolver {
		return new PageTemplateResolver( array( $blogId => $context ) );
	}

	public function testPageOnStockThemeFileIsUnremarkable(): void {
		$result = $this->resolver(
			$this->context( array( 'themeTemplateSlugs' => array( 'page', 'index' ) ) )
		)->describe( $this->post() );

		self::assertSame(
			array(
			'template' => 'page',
			'status' => '',
			),
			$result
		);
	}

	public function testPageOnActiveThemeRowIsCustomized(): void {
		$result = $this->resolver(
			$this->context( array( 'activeTemplateSlugs' => array( 'page' ) ) )
		)->describe( $this->post() );

		self::assertSame(
			array(
			'template' => 'page',
			'status' => 'customized',
			),
			$result
		);
	}

	public function testPageWhoseOnlyCustomizationIsStaleIsRetagCandidate(): void {
		// 'page' exists only as a twentytwentythree row and no theme
		// file provides it -- retagging the row restores the page.
		$result = $this->resolver(
			$this->context(
				array(
					'themeTemplateSlugs' => array( 'index' ),
					'staleTemplateRows' => array( 'page' => 'twentytwentythree' ),
				)
			)
		)->describe( $this->post() );

		self::assertSame(
			array(
			'template' => 'twentytwentythree//page',
			'status' => 'stale-customization',
			),
			$result
		);
	}

	public function testStaleSpecificCandidateWinsOverGenericFallback(): void {
		// A product would use single-product, but that row is stale;
		// it currently falls back to 'single'. Report the stale slug --
		// it is what the page used to render with.
		$result = $this->resolver(
			$this->context(
				array(
					'themeTemplateSlugs' => array( 'index', 'single' ),
					'staleTemplateRows' => array( 'single-product' => 'twentytwentythree' ),
				)
			)
		)->describe( $this->post( 'product' ) );

		self::assertSame(
			array(
			'template' => 'twentytwentythree//single-product',
			'status' => 'stale-customization',
			),
			$result
		);
	}

	public function testExplicitActiveThemeTemplate(): void {
		$result = $this->resolver(
			$this->context( array( 'themeTemplateSlugs' => array( 'landing' ) ) )
		)->describe( $this->post( 'page', 'mytheme//landing' ) );

		self::assertSame(
			array(
			'template' => 'mytheme//landing',
			'status' => 'custom-template',
			),
			$result
		);
	}

	public function testExplicitMissingTemplate(): void {
		$result = $this->resolver(
			$this->context( array( 'themeTemplateSlugs' => array( 'page' ) ) )
		)->describe( $this->post( 'page', 'mytheme//landing' ) );

		self::assertSame( 'missing-template', $result['status'] );
	}

	public function testExplicitOtherThemeTemplate(): void {
		$result = $this->resolver( $this->context() )
			->describe( $this->post( 'page', 'twentytwentythree//landing' ) );

		self::assertSame(
			array(
			'template' => 'twentytwentythree//landing',
			'status' => 'stale-theme-template',
			),
			$result
		);
	}

	public function testExplicitPluginTemplate(): void {
		$result = $this->resolver( $this->context() )
			->describe( $this->post( 'page', 'woocommerce/woocommerce//page-cart' ) );

		self::assertSame( 'plugin-template', $result['status'] );
	}

	public function testExplicitClassicPhpTemplate(): void {
		$result = $this->resolver( $this->context() )
			->describe( $this->post( 'page', 'pages/template-surecart-dashboard.php' ) );

		self::assertSame(
			array(
			'template' => 'pages/template-surecart-dashboard.php',
			'status' => 'classic-template',
			),
			$result
		);
	}

	public function testFrontPageResolvesFrontPageFirst(): void {
		$result = $this->resolver(
			$this->context(
				array(
					'pageOnFront' => 10,
					'themeTemplateSlugs' => array( 'front-page', 'index', 'page' ),
				)
			)
		)->describe( $this->post( 'page', null, 1, 10 ) );

		self::assertSame( 'front-page', $result['template'] );
	}

	public function testClassicThemeSiteIsLabeledNotBlank(): void {
		// No block-theme signal at all: PHP templates are invisible to
		// this inventory, so the row is labeled 'classic theme' rather
		// than guessed at.
		$result = $this->resolver( $this->context() )->describe( $this->post() );

		self::assertSame(
			array(
			'template' => 'classic theme',
			'status' => 'classic-theme',
			),
			$result
		);
	}

	public function testExplicitPhpTemplateStillReportedOnClassicTheme(): void {
		// _wp_page_template selections are visible even where the theme
		// hierarchy isn't.
		$result = $this->resolver( $this->context() )
			->describe( $this->post( 'page', 'pages/template-surecart-dashboard.php' ) );

		self::assertSame( 'classic-template', $result['status'] );
	}

	public function testNonFrontendTypeReportsNothing(): void {
		$result = $this->resolver( $this->context() )
			->describe( $this->post( 'nav_menu_item', 'mytheme//landing' ) );

		self::assertSame(
			array(
			'template' => '',
			'status' => '',
			),
			$result
		);
	}

	public function testStaleRowOnResolvedSlugIsFlagged(): void {
		// 'single' resolves via a theme file, but a dormant
		// customization exists under the old theme -- the page used to
		// render it and would again if the row were retagged.
		$result = $this->resolver(
			$this->context(
				array(
					'themeTemplateSlugs' => array( 'index', 'single' ),
					'staleTemplateRows' => array( 'single' => 'twentytwentythree' ),
				)
			)
		)->describe( $this->post( 'post' ) );

		self::assertSame(
			array(
				'template' => 'twentytwentythree//single',
				'status' => 'stale-customization',
			),
			$result
		);
	}

	public function testStaleSiblingBesideActiveRowIsNotFlagged(): void {
		// An active 'page' row is live; a stale 'page' row from an old
		// theme is a merge collision, not a missing template.
		$result = $this->resolver(
			$this->context(
				array(
					'activeTemplateSlugs' => array( 'page' ),
					'staleTemplateRows' => array( 'page' => 'twentytwentythree' ),
				)
			)
		)->describe( $this->post() );

		self::assertSame(
			array(
				'template' => 'page',
				'status' => 'customized',
			),
			$result
		);
	}

	public function testNoTemplateAtAll(): void {
		// Block theme signals exist but nothing renders this post type.
		$result = $this->resolver(
			$this->context( array( 'themeTemplateSlugs' => array( 'index', 'page' ) ) )
		)->describe( $this->post( 'book' ) );

		self::assertSame( 'no-template', $result['status'] );
		self::assertSame( 'single-book', $result['template'] );
	}
}
