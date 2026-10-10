<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\ContentAudit\ContentAuditRow;
use MergeMultisite\ContentAudit\ScannedPost;
use MergeMultisite\Report\NeedsReviewReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( NeedsReviewReportWriter::class )]
final class NeedsReviewReportWriterTest extends TestCase {

	/**
	 * The toRows() method emits one row per page flagged needs_review
	 * (original + guessed destination URL, detected plugins, raw Divi
	 * shortcode dump), skips pages without the flag, and skips
	 * revisions.
	 */
	public function testToRowsEmitsOnlyNeedsReviewPagesWithRawData(): void {
		$writer = new NeedsReviewReportWriter();

		$details = array(
			array(
				'row'  => new ContentAuditRow(
					blogId: 35,
					domain: 'molten-salt-reactor.lc.lndo.site',
					postId: 100,
					postType: 'page',
					postStatus: 'publish',
					slug: 'reactor-design',
					postTitle: 'Reactor Design',
					categoryFindings: array( 'needs_review' => array( 'Divi' ) ),
				),
				'post' => new ScannedPost(
					blogId: 35,
					postId: 100,
					postType: 'page',
					postStatus: 'publish',
					slug: 'reactor-design',
					postTitle: 'Reactor Design',
					content: '[et_pb_section][et_pb_text]Fuel[/et_pb_text][/et_pb_section]',
					meta: array(),
				),
			),
			array(
				// Ordinary page: no needs_review flag, so no row.
				'row'  => new ContentAuditRow(
					blogId: 35,
					domain: 'molten-salt-reactor.lc.lndo.site',
					postId: 101,
					postType: 'page',
					postStatus: 'publish',
					slug: 'about',
					postTitle: 'About',
					categoryFindings: array(),
				),
				'post' => new ScannedPost(
					blogId: 35,
					postId: 101,
					postType: 'page',
					postStatus: 'publish',
					slug: 'about',
					postTitle: 'About',
					content: '<p>hi</p>',
					meta: array(),
				),
			),
			array(
				// Revision of the Divi page: never migrated, no row.
				'row'  => new ContentAuditRow(
					blogId: 35,
					domain: 'molten-salt-reactor.lc.lndo.site',
					postId: 200,
					postType: 'revision',
					postStatus: 'inherit',
					slug: '100-revision-v1',
					postTitle: 'Reactor Design',
					categoryFindings: array( 'needs_review' => array( 'Divi' ) ),
				),
				'post' => new ScannedPost(
					blogId: 35,
					postId: 200,
					postType: 'revision',
					postStatus: 'inherit',
					slug: '100-revision-v1',
					postTitle: 'Reactor Design',
					content: '[et_pb_section][/et_pb_section]',
					meta: array(),
				),
			),
		);

		$rows = $writer->toRows( $details, 'https://example.com' );

		self::assertCount( 1, $rows );
		self::assertSame( '35', $rows[0]['blog_id'] );
		self::assertSame( 'https://molten-salt-reactor.lc.lndo.site/reactor-design/', $rows[0]['original_url'] );
		self::assertSame( 'https://example.com/reactor-design/', $rows[0]['destination_url (guess)'] );
		self::assertSame( 'Divi', $rows[0]['plugins_that_need_checking'] );
		self::assertStringContainsString( 'Divi shortcode attrs', $rows[0]['raw_data'] );
		self::assertStringContainsString( '[et_pb_section]', $rows[0]['raw_data'] );
	}

	/**
	 * A draft with no post_name has no slug to build a URL from.
	 * The original_url falls back to WordPress's '?p={id}' form
	 * (rather than a broken "https://domain//"), and the destination
	 * guess is left empty -- the destination ID isn't known, so any
	 * guess would be wrong. Trashed posts are skipped outright.
	 */
	public function testSluglessAndTrashedRows(): void {
		$writer = new NeedsReviewReportWriter();

		$details = array(
			array(
				// Draft that never got a slug (real case: a
				// scratch draft later trashed).
				'row'  => new ContentAuditRow(
					blogId: 2,
					domain: 'website-tech.lc.lndo.site',
					postId: 1408,
					postType: 'post',
					postStatus: 'draft',
					slug: '',
					postTitle: 'ChatGPT for Color Palettes',
					categoryFindings: array( 'needs_review' => array( 'Spectra Form' ) ),
				),
				'post' => new ScannedPost(
					blogId: 2,
					postId: 1408,
					postType: 'post',
					postStatus: 'draft',
					slug: '',
					postTitle: 'ChatGPT for Color Palettes',
					content: '<p>x</p>',
					meta: array(),
				),
			),
			array(
				// Trashed row fed in anyway (e.g. a future caller
				// forgetting the status filter): never reviewed.
				'row'  => new ContentAuditRow(
					blogId: 2,
					domain: 'website-tech.lc.lndo.site',
					postId: 1409,
					postType: 'post',
					postStatus: 'trash',
					slug: 'old-post__trashed',
					postTitle: 'Old Post',
					categoryFindings: array( 'needs_review' => array( 'Divi' ) ),
				),
				'post' => new ScannedPost(
					blogId: 2,
					postId: 1409,
					postType: 'post',
					postStatus: 'trash',
					slug: 'old-post__trashed',
					postTitle: 'Old Post',
					content: '<p>x</p>',
					meta: array(),
				),
			),
		);

		$rows = $writer->toRows( $details, 'https://example.com' );

		self::assertCount( 1, $rows );
		self::assertSame( 'https://website-tech.lc.lndo.site/?p=1408', $rows[0]['original_url'] );
		self::assertSame( '', $rows[0]['destination_url (guess)'] );
		self::assertSame( 'draft', $rows[0]['post_status'] );
	}

	/**
	 * A hierarchical page's original_url must use its parent/child
	 * path -- matching the main audit tab -- not the bare leaf slug.
	 */
	public function testHierarchicalPathUsedForOriginalUrl(): void {
		$writer = new NeedsReviewReportWriter();

		$details = array(
			array(
				'row'  => new ContentAuditRow(
					blogId: 1,
					domain: 'example.lc.lndo.site',
					postId: 50,
					postType: 'page',
					postStatus: 'publish',
					slug: 'child',
					postTitle: 'Child',
					categoryFindings: array( 'needs_review' => array( 'Divi' ) ),
					path: 'parent/child',
				),
				'post' => new ScannedPost(
					blogId: 1,
					postId: 50,
					postType: 'page',
					postStatus: 'publish',
					slug: 'child',
					postTitle: 'Child',
					content: '<p>x</p>',
					meta: array(),
				),
			),
		);

		$rows = $writer->toRows( $details, 'https://example.com' );

		self::assertCount( 1, $rows );
		self::assertSame( 'https://example.lc.lndo.site/parent/child/', $rows[0]['original_url'] );
		self::assertSame( 'https://example.com/parent/child/', $rows[0]['destination_url (guess)'] );
	}

	/**
	 * The CSV and the xlsx tab share the same row builder, so a CSV
	 * written from toRows() parses back to the same fields.
	 */
	public function testToCsvMatchesToRowsShape(): void {
		$writer = new NeedsReviewReportWriter();

		$details = array(
			array(
				'row'  => new ContentAuditRow(
					blogId: 1,
					domain: 'lc.lndo.site',
					postId: 10,
					postType: 'page',
					postStatus: 'publish',
					slug: 'services',
					postTitle: 'Services',
					categoryFindings: array( 'needs_review' => array( 'Elementor' ) ),
				),
				'post' => new ScannedPost(
					blogId: 1,
					postId: 10,
					postType: 'page',
					postStatus: 'publish',
					slug: 'services',
					postTitle: 'Services',
					content: '<p>x</p>',
					meta: array(),
				),
			),
		);

		$csv = $writer->toCsv( $details, 'https://example.com' );

		// fputcsv quotes fields containing spaces, hence the quotes on
		// "destination_url (guess)".
		self::assertStringContainsString( 'blog_id,original_url,"destination_url (guess)",post_id', $csv );
		self::assertStringContainsString( '1,https://lc.lndo.site/services/,https://example.com/services/', $csv );
	}
}
