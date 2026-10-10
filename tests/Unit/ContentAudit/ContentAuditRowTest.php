<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\ContentAuditRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * URL construction in toRow(): hierarchical path wins, then the bare
 * slug, and a post with neither (a slugless draft) falls back to
 * WordPress's '?p={id}' permalink form rather than emitting a broken
 * "https://domain//".
 */
#[CoversClass( ContentAuditRow::class )]
final class ContentAuditRowTest extends TestCase {

	public function testSluglessPostGetsIdPermalink(): void {
		$row = new ContentAuditRow(
			blogId: 2,
			domain: 'website-tech.lc.lndo.site',
			postId: 1408,
			postType: 'post',
			postStatus: 'draft',
			slug: '',
			postTitle: 'ChatGPT for Color Palettes',
			categoryFindings: array(),
		);

		self::assertSame( 'https://website-tech.lc.lndo.site/?p=1408', $row->toRow()['original_url'] );
	}

	public function testHierarchicalPathWinsOverBareSlug(): void {
		$row = new ContentAuditRow(
			blogId: 1,
			domain: 'example.lc.lndo.site',
			postId: 50,
			postType: 'page',
			postStatus: 'publish',
			slug: 'child',
			postTitle: 'Child',
			categoryFindings: array(),
			path: 'parent/child',
		);

		self::assertSame( 'https://example.lc.lndo.site/parent/child/', $row->toRow()['original_url'] );
	}
}
