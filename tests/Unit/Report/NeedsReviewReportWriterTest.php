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
