<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Report\MediaFilenameReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MediaFilenameReportWriter row flattening: findings carry rich
 * context, the spreadsheet rows must render every context field into
 * its own column (issues joined, used_in rendered per post).
 *
 * @package MergeMultisite
 */
#[CoversClass( MediaFilenameReportWriter::class )]
final class MediaFilenameReportWriterTest extends TestCase {

	public function testToRowsFlattensFindingContext(): void {
		$finding = AuditFinding::warning(
			'media-filenames.problematic-filename',
			'Attachment 12 (site 1): ...',
			array(
				'blog_id' => 1,
				'post_id' => 12,
				'file' => '2024/12/DALL·E-2024-12-19--e1734673835640.webp',
				'issues' => array(
					array(
						'type' => 'non-ascii',
						'detail' => 'non-ASCII character(s): ·',
					),
					array(
						'type' => 'space',
						'detail' => 'contains space(s)',
					),
				),
				'suggested_name' => 'dall-e-2024-12-19.webp',
				'suggested_from' => 'filename',
				'alt_text' => 'Futuristic design',
				'caption' => 'A caption',
				'title' => 'Attachment title',
				'used_in' => array(
					array(
						'post_id' => 55,
						'post_type' => 'page',
						'post_title' => 'Uses the image',
					),
				),
			)
		);

		$rows = ( new MediaFilenameReportWriter() )->toRows( array( $finding ) );

		self::assertCount( 1, $rows );
		self::assertSame(
			array(
				'site_id' => '1',
				'attachment_id' => '12',
				'file' => '2024/12/DALL·E-2024-12-19--e1734673835640.webp',
				'issues' => 'non-ASCII character(s): ·; contains space(s)',
				'suggested_name' => 'dall-e-2024-12-19.webp',
				'suggested_from' => 'filename',
				'alt_text' => 'Futuristic design',
				'caption' => 'A caption',
				'attachment_title' => 'Attachment title',
				'used_in' => 'Uses the image (page, post 55)',
			),
			$rows[0]
		);
	}

	public function testColumnsAreStable(): void {
		self::assertSame(
			array(
				'site_id',
				'attachment_id',
				'file',
				'issues',
				'suggested_name',
				'suggested_from',
				'alt_text',
				'caption',
				'attachment_title',
				'used_in',
			),
			MediaFilenameReportWriter::columns()
		);
	}
}
