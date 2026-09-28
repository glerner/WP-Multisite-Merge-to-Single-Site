<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\PostQueryHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PostQueryHelper::class )]
final class PostQueryHelperTest extends TestCase {

	public function testPostStatusClauseExcludesTrashAndAutoDraftByDefault(): void {
		$params = array();
		$clause = PostQueryHelper::postStatusClause( array(), $params );

		self::assertStringStartsWith( 'post_status NOT IN (', $clause );
		self::assertContains( 'trash', $params );
		self::assertContains( 'auto-draft', $params );
		self::assertCount( 2, $params );
	}

	public function testPostStatusClauseMergesConfiguredExclusions(): void {
		$params = array();
		$clause = PostQueryHelper::postStatusClause( array( 'draft', 'inherit' ), $params );

		self::assertContains( 'trash', $params );
		self::assertContains( 'auto-draft', $params );
		self::assertContains( 'draft', $params );
		self::assertContains( 'inherit', $params );
		self::assertCount( 4, $params );
	}

	public function testPostTypeClauseUsesInClauseWhenPostTypesProvided(): void {
		$params = array();
		$clause = PostQueryHelper::postTypeClause( array( 'post', 'page' ), array( 'revision' ), $params );

		self::assertStringContainsString( 'post_type IN (', $clause );
		self::assertContains( 'post', $params );
		self::assertContains( 'page', $params );
		self::assertNotContains( 'revision', $params );
	}

	public function testPostTypeClauseUsesNotInClauseWhenExcludedPostTypesProvided(): void {
		$params = array();
		$clause = PostQueryHelper::postTypeClause( array(), array( 'revision', 'nav_menu_item' ), $params );

		self::assertStringContainsString( 'post_type NOT IN (', $clause );
		self::assertContains( 'revision', $params );
		self::assertContains( 'nav_menu_item', $params );
	}

	public function testPostTypeClauseReturnsEmptyStringWhenNoTypesOrExclusions(): void {
		$params = array();
		$clause = PostQueryHelper::postTypeClause( array(), array(), $params );

		self::assertSame( '', $clause );
		self::assertSame( array(), $params );
	}
}
