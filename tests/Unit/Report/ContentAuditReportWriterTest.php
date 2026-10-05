<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\ContentAudit\ContentAuditRow;
use MergeMultisite\Report\ContentAuditReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ContentAuditReportWriter::class )]
final class ContentAuditReportWriterTest extends TestCase {

	/**
	 * Rows with post_type=custom_css (the Customizer's "Additional CSS", where
	 * post_name is the theme stylesheet the CSS belongs to) get quoted
	 * verbatim in a fenced block at the end of the Markdown summary --
	 * one ### heading per site. Rows with empty content are skipped,
	 * and ordinary page content is never quoted.
	 */
	public function testCustomCssPostsAreQuotedInSummary(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array(
			new ContentAuditRow(
				blogId: 20,
				domain: 'website-tech.lc.lndo.site',
				postId: 500,
				postType: 'custom_css',
				postStatus: 'publish',
				slug: 'website-tech',           // post_name = owning theme
				postTitle: 'website-tech',
				categoryFindings: array(),
				content: 'body { color: red; }',
			),
			new ContentAuditRow(
				blogId: 33,
				domain: 'healthwellness.lc.lndo.site',
				postId: 12,
				postType: 'custom_css',
				postStatus: 'publish',
				slug: 'Divi',
				postTitle: 'Divi',
				categoryFindings: array(),
				content: '.hero { padding: 2em; }',
			),
			// Empty Additional CSS post: produces no heading at all.
			new ContentAuditRow(
				blogId: 58,
				domain: 'computerhelp.lc.lndo.site',
				postId: 900,
				postType: 'custom_css',
				postStatus: 'publish',
				slug: 'website-tech',
				postTitle: 'website-tech',
				categoryFindings: array(),
				content: "  \n ",
			),
			// A normal page: its content must NOT leak into the summary.
			new ContentAuditRow(
				blogId: 20,
				domain: 'website-tech.lc.lndo.site',
				postId: 10,
				postType: 'page',
				postStatus: 'publish',
				slug: 'about',
				postTitle: 'About',
				categoryFindings: array(),
				template: 'page',
				content: '<p>hi</p>',
			),
		);

		$summary = $writer->toSummary( $rows, array( 'form_plugin' ) );

		self::assertStringContainsString( '## Customizer Additional CSS', $summary );
		self::assertStringContainsString( '### Site 20 — website-tech.lc.lndo.site (`website-tech`)', $summary );
		self::assertStringContainsString( "```css\nbody { color: red; }\n```", $summary );
		self::assertStringContainsString( '.hero { padding: 2em; }', $summary );
		// The empty site-58 row produces no heading.
		self::assertStringNotContainsString( 'Site 58', $summary );
		// Page content is never quoted.
		self::assertStringNotContainsString( '<p>hi</p>', $summary );
	}

	/**
	 * Rows with post_status=future get a "Scheduled posts" section listing
	 * per-site counts plus a pointer to bin/schedule-future-posts.sh --
	 * cron events don't travel with the posts table, so the reader has
	 * to decide whether to re-schedule or leave them unpublished.
	 */
	public function testFuturePostsGetASummarySection(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array(
			$this->postRow( 20, 'a.test', 'soon', 'future' ),
			$this->postRow( 20, 'a.test', 'later', 'future' ),
			$this->postRow( 33, 'b.test', 'next', 'future' ),
			// Published post: must not be counted.
			$this->postRow( 20, 'a.test', 'done', 'publish' ),
		);

		$summary = $writer->toSummary( $rows, array() );

		self::assertStringContainsString( '## Scheduled posts (post_status=future)', $summary );
		self::assertStringContainsString( '- Site 20: 2 post(s)', $summary );
		self::assertStringContainsString( '- Site 33: 1 post(s)', $summary );
		self::assertStringContainsString( 'schedule-future-posts.sh', $summary );
	}

	/**
	 * With no future posts, the section is omitted entirely rather
	 * than rendering an empty heading.
	 */
	public function testNoFuturePostsMeansNoSection(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array( $this->postRow( 20, 'a.test', 'done', 'publish' ) );

		self::assertStringNotContainsString( 'Scheduled posts', $writer->toSummary( $rows, array() ) );
	}

	/**
	 * With no custom_css posts, the section is omitted entirely rather
	 * than rendering an empty heading.
	 */
	public function testNoCustomCssPostsMeansNoSection(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array(
			new ContentAuditRow(
				blogId: 20,
				domain: 'x.test',
				postId: 10,
				postType: 'page',
				postStatus: 'publish',
				slug: 'about',
				postTitle: 'About',
				categoryFindings: array( 'form_plugin' => array( 'WPForms' ) ),
			),
		);

		$summary = $writer->toSummary( $rows, array( 'form_plugin' ) );

		self::assertStringNotContainsString( 'Customizer Additional CSS', $summary );
	}

	/**
	 * The blockInventory() method emits one row per distinct (block,
	 * page) pair, resolves the owning plugin from the PluginUsageRollup
	 * result (used entities carry name + slug; not_installed carry name
	 * only), and repeats the block's total occurrence count on each of
	 * its rows. Filtering "Used On URL" therefore yields every page
	 * using a block.
	 */
	public function testBlockInventoryEmitsOneRowPerBlockUrlPair(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array(
			new ContentAuditRow(
				blogId: 20,
				domain: 'a.test',
				postId: 1,
				postType: 'page',
				postStatus: 'publish',
				slug: 'shop',
				postTitle: 'Shop',
				categoryFindings: array(
					'blocks' => array( 'sureforms/form-selector', 'uagb/forms' ),
				),
			),
			new ContentAuditRow(
				blogId: 33,
				domain: 'b.test',
				postId: 2,
				postType: 'page',
				postStatus: 'publish',
				slug: 'contact',
				postTitle: 'Contact',
				categoryFindings: array(
					'blocks' => array( 'sureforms/form-selector' ),
				),
			),
		);

		$pluginUsage = array(
			'used' => array(
				'SureForms' => array(
					'slug'    => 'sureforms',
					'signals' => array( 'blocks|sureforms/form-selector' ),
					'sites'   => array( 20, 33 ),
				),
				'Spectra (Ultimate Addons for Gutenberg)' => array(
					'slug'    => 'ultimate-addons-for-gutenberg',
					'signals' => array( 'blocks|uagb/forms' ),
					'sites'   => array( 20 ),
				),
			),
			'not_installed' => array(),
		);

		$inventory = $writer->blockInventory( $rows, $pluginUsage );

		// sureforms/form-selector has two usage rows (one per URL) with
		// the block's total occurrences repeated; uagb/forms one row.
		self::assertCount( 3, $inventory );

		self::assertSame( 'sureforms/form-selector', $inventory[0]['block'] );
		self::assertSame( 'SureForms', $inventory[0]['plugin'] );
		self::assertSame( 'sureforms', $inventory[0]['plugin_slug'] );
		self::assertSame( 2, $inventory[0]['occurrences'] );
		self::assertSame( 'https://a.test/shop/', $inventory[0]['url'] );

		self::assertSame( 'sureforms/form-selector', $inventory[1]['block'] );
		self::assertSame( 2, $inventory[1]['occurrences'] );
		self::assertSame( 'https://b.test/contact/', $inventory[1]['url'] );

		self::assertSame( 'uagb/forms', $inventory[2]['block'] );
		self::assertSame( 'Spectra (Ultimate Addons for Gutenberg)', $inventory[2]['plugin'] );
		self::assertSame( 'ultimate-addons-for-gutenberg', $inventory[2]['plugin_slug'] );
		self::assertSame( 1, $inventory[2]['occurrences'] );
		self::assertSame( 'https://a.test/shop/', $inventory[2]['url'] );
	}

	/**
	 * A block with no resolved owner (unmapped namespace, or no
	 * pluginUsage passed in) still appears in the inventory with empty
	 * owner columns -- the census must be complete, and a blank owner
	 * is a signal to map the namespace in plugin-roles.php.
	 */
	public function testBlockInventoryKeepsBlocksWithoutResolvedOwner(): void {
		$writer = new ContentAuditReportWriter();

		$rows = array(
			new ContentAuditRow(
				blogId: 20,
				domain: 'a.test',
				postId: 1,
				postType: 'page',
				postStatus: 'publish',
				slug: 'home',
				postTitle: 'Home',
				categoryFindings: array( 'blocks' => array( 'mystery-namespace/widget' ) ),
			),
		);

		$inventory = $writer->blockInventory( $rows );

		self::assertCount( 1, $inventory );
		self::assertSame( 'mystery-namespace/widget', $inventory[0]['block'] );
		self::assertSame( '', $inventory[0]['plugin'] );
		self::assertSame( '', $inventory[0]['plugin_slug'] );
		self::assertSame( 1, $inventory[0]['occurrences'] );
		self::assertSame( 'https://a.test/home/', $inventory[0]['url'] );
	}

	private function postRow( int $blogId, string $domain, string $slug, string $status ): ContentAuditRow {
		return new ContentAuditRow(
			blogId: $blogId,
			domain: $domain,
			postId: 1,
			postType: 'post',
			postStatus: $status,
			slug: $slug,
			postTitle: ucfirst( $slug ),
			categoryFindings: array(),
		);
	}
}
