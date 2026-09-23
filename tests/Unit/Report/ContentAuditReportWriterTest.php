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
