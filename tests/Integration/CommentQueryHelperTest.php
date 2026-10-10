<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\CommentQueryHelper;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: CommentQueryHelper against a real (SQLite) database —
 * keyset batch boundaries (no OFFSET, no skipped or duplicated rows)
 * and commentmeta IN()-chunking past MySQL's 5000-id chunk size.
 *
 * @package MergeMultisite
 */
#[CoversClass( CommentQueryHelper::class )]
final class CommentQueryHelperTest extends SqliteTestCase {

	private Connection $source;

	protected function setUp(): void {
		parent::setUp();
		$this->source = $this->sqliteConnection( 'wp_', array( 1 ) );
	}

	public function testCommentBatchesPaginationIsContinuousAcrossBatches(): void {
		$commentsTable = $this->source->siteTable( 'comments', 1 );

		$expectedIds = array();
		for ( $i = 1; $i <= 7; ++$i ) {
			$expectedIds[] = WpTestSchema::insertComment(
				$this->source->pdo(),
				$commentsTable,
				array(
				'comment_post_ID' => 5,
				'comment_content' => 'comment ' . $i,
				)
			);
		}

		$batches = iterator_to_array(
			CommentQueryHelper::commentBatches( $this->source, $commentsTable, 3 )
		);

		// 7 comments, batch size 3 => batches of 3/3/1.
		self::assertSame( array( 0, 1, 2 ), array_keys( $batches ) );
		self::assertCount( 3, $batches[0] );
		self::assertCount( 3, $batches[1] );
		self::assertCount( 1, $batches[2] );

		$seenIds = array();
		foreach ( $batches as $batch ) {
			foreach ( $batch as $row ) {
				$seenIds[] = (int) $row['comment_ID'];
			}
		}

		// Every comment exactly once, in ID order, keyset-continuous.
		self::assertSame( $expectedIds, $seenIds );
	}

	public function testCommentBatchesYieldsNothingForEmptyTable(): void {
		$commentsTable = $this->source->siteTable( 'comments', 1 );

		$batches = iterator_to_array(
			CommentQueryHelper::commentBatches( $this->source, $commentsTable, 3 )
		);

		self::assertSame( array(), $batches );
	}

	public function testFetchMetaForCommentsGroupsValuesByComment(): void {
		$commentMetaTable = $this->source->siteTable( 'commentmeta', 1 );
		$commentsTable = $this->source->siteTable( 'comments', 1 );

		$c1 = WpTestSchema::insertComment( $this->source->pdo(), $commentsTable );
		$c2 = WpTestSchema::insertComment( $this->source->pdo(), $commentsTable );

		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$commentMetaTable,
			array(
			'comment_id' => $c1,
			'meta_key' => 'rating',
			'meta_value' => '5',
			)
		);
		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$commentMetaTable,
			array(
			'comment_id' => $c1,
			'meta_key' => 'rating',
			'meta_value' => '4',
			)
		);
		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$commentMetaTable,
			array(
			'comment_id' => $c2,
			'meta_key' => 'rating',
			'meta_value' => '3',
			)
		);

		$meta = CommentQueryHelper::fetchMetaForComments( $this->source, $commentMetaTable, array( $c1, $c2 ) );

		self::assertSame( array( '5', '4' ), $meta[ $c1 ]['rating'] );
		self::assertSame( array( '3' ), $meta[ $c2 ]['rating'] );
	}

	public function testFetchMetaForCommentsChunksPastFiveThousandIds(): void {
		$commentMetaTable = $this->source->siteTable( 'commentmeta', 1 );

		// 5005 ids => two IN() chunks (5000 + 5), the MySQL-safe bound.
		$ids = array();
		for ( $i = 1; $i <= 5005; ++$i ) {
			$ids[] = $i;
			WpTestSchema::insertCommentMeta(
				$this->source->pdo(),
				$commentMetaTable,
				array(
				'comment_id' => $i,
				'meta_key' => 'k',
				'meta_value' => (string) $i,
				)
			);
		}

		$meta = CommentQueryHelper::fetchMetaForComments( $this->source, $commentMetaTable, $ids );

		self::assertCount( 5005, $meta );
		self::assertSame( array( '5005' ), $meta[5005]['k'] );
		self::assertSame( array( '1' ), $meta[1]['k'] );
	}

	public function testFetchMetaForCommentsReturnsEmptyForNoIds(): void {
		$commentMetaTable = $this->source->siteTable( 'commentmeta', 1 );

		self::assertSame( array(), CommentQueryHelper::fetchMetaForComments( $this->source, $commentMetaTable, array() ) );
	}
}
