<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\PostQueryHelper;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: PostQueryHelper::fetchMetaForPosts() against a real (SQLite)
 * database — the postmeta shape WordPress actually uses
 * (`meta_key => value[]`) and the 5000-id IN() chunking boundary that
 * keeps queries under MySQL's 65535 prepared-statement limit.
 *
 * @package MergeMultisite
 */
#[CoversClass( PostQueryHelper::class )]
final class PostQueryHelperMetaTest extends SqliteTestCase {

	private Connection $source;

	protected function setUp(): void {
		parent::setUp();
		$this->source = $this->sqliteConnection( 'wp_', array( 1 ) );
	}

	public function testFetchMetaForPostsGroupsValuesByMetaKey(): void {
		$postMetaTable = $this->source->siteTable( 'postmeta', 1 );

		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => 10,
			'meta_key' => '_thumbnail_id',
			'meta_value' => '3',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => 10,
			'meta_key' => 'tag',
			'meta_value' => 'a',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => 10,
			'meta_key' => 'tag',
			'meta_value' => 'b',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => 11,
			'meta_key' => '_thumbnail_id',
			'meta_value' => '7',
			)
		);

		$meta = PostQueryHelper::fetchMetaForPosts( $this->source, $postMetaTable, array( 10, 11 ) );

		self::assertSame( array( '3' ), $meta[10]['_thumbnail_id'] );
		self::assertSame( array( 'a', 'b' ), $meta[10]['tag'] );
		self::assertSame( array( '7' ), $meta[11]['_thumbnail_id'] );
	}

	public function testFetchMetaForPostsChunksPastFiveThousandIds(): void {
		$postMetaTable = $this->source->siteTable( 'postmeta', 1 );

		// 5005 ids => two IN() chunks (5000 + 5); proves the chunking
		// loop actually splits, not just that a small IN works.
		$ids = array();
		for ( $i = 1; $i <= 5005; ++$i ) {
			$ids[] = $i;
			WpTestSchema::insertPostMeta(
				$this->source->pdo(),
				$postMetaTable,
				array(
				'post_id' => $i,
				'meta_key' => 'k',
				'meta_value' => (string) $i,
				)
			);
		}

		$meta = PostQueryHelper::fetchMetaForPosts( $this->source, $postMetaTable, $ids );

		self::assertCount( 5005, $meta );
		self::assertSame( array( '5005' ), $meta[5005]['k'] );
		self::assertSame( array( '1' ), $meta[1]['k'] );
	}

	public function testFetchMetaForPostsIgnoresPostIdsWithoutMeta(): void {
		$postMetaTable = $this->source->siteTable( 'postmeta', 1 );
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => 1,
			'meta_key' => 'k',
			'meta_value' => 'v',
			)
		);

		$meta = PostQueryHelper::fetchMetaForPosts( $this->source, $postMetaTable, array( 1, 999 ) );

		self::assertArrayHasKey( 1, $meta );
		self::assertArrayNotHasKey( 999, $meta );
	}
}
