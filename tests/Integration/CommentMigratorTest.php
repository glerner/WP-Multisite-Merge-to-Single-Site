<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\CommentMigrator;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\Site;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CommentMigrator end-to-end on SQLite: comment_post_ID / user_id /
 * comment_parent remapping through the IdMaps, serialized commentmeta
 * rewriting with byte-length safety, the spam/trash discard policy
 * (counted, never silent), comments on unmigrated posts dropped and
 * counted, guest user_id preservation, dry-run writing nothing, and
 * resume idempotency.
 *
 * The IdMap is seeded the way the earlier phases (users, posts, media)
 * leave it, so this test isolates CommentMigrator's own behaviour.
 *
 * @package MergeMultisite
 */
#[CoversClass( CommentMigrator::class )]
final class CommentMigratorTest extends SqliteTestCase {

	private Connection $source;

	private Connection $destination;

	private MigrationTable $mapTable;

	private IdMap $idMap;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->sqliteConnection( 'wp_', array( 1, 7 ) );
		$this->destination = $this->sqliteConnection( 'wp_', array( 1 ) );
		$this->mapTable = new MigrationTable();
		$this->mapTable->ensure( $this->destination );

		$this->idMap = new IdMap();
		$this->seedEarlierPhases();
	}

	/**
	 * What Users/Posts/Media leave in the IdMap for this fixture.
	 * User 3 deliberately has NO mapping (the fallback-to-guest case);
	 * post 199 deliberately has none (the dropped-comment case).
	 */
	private function seedEarlierPhases(): void {
		$this->idMap->set( 'user', 0, 1, 10 );
		$this->idMap->set( 'user', 0, 2, 20 );

		$this->idMap->set( 'post', 1, 101, 201 );
		$this->idMap->set( 'post', 1, 102, 202 );
		$this->idMap->set( 'attachment', 1, 501, 901 );

		$this->idMap->set( 'post', 7, 201, 301 );

		// Destination post rows as PostMigrator would have left them
		// (no FK in the fixture, but keeps the shape honest).
		$destPosts = $this->destination->siteTable( 'posts', 1 );
		foreach ( array( 201, 202, 301, 901 ) as $postId ) {
			WpTestSchema::insertPost(
				$this->destination->pdo(),
				$destPosts,
				array( 'ID' => $postId )
			);
		}
	}

	/**
	 * Site 1:
	 *  11  approved, user 1 (-> 10), post 101, parent 0, rating meta.
	 *  12  pending, guest, post 101, parent 11 (threaded reply), meta
	 *      whose serialized akismet_history references comment 11 +
	 *      user 1 (both must be remapped, byte lengths fixed).
	 *  13  approved, user 2 (-> 20), post 501 (attachment -> 901).
	 *  14  approved, user 3 (unmapped -> guest 0).
	 *  15  approved, post 199 (unmigrated post -> dropped).
	 *  16  spam (discarded, counted).
	 *  17  trash (discarded, counted).
	 *  18  approved, guest, post 101, parent 16 (spam -> unresolved).
	 */
	private function seedSiteOneComments(): void {
		$commentsTable = $this->source->siteTable( 'comments', 1 );
		$metaTable = $this->source->siteTable( 'commentmeta', 1 );

		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 11,
				'comment_post_ID' => 101,
				'comment_author' => 'Alice',
				'comment_author_email' => 'alice@example.test',
				'comment_content' => 'First',
				'comment_approved' => '1',
				'user_id' => 1,
			)
		);
		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$metaTable,
			array(
				'comment_id' => 11,
				'meta_key' => 'rating',
				'meta_value' => '5',
			)
		);
		// Serialized list of plain integers: must pass through
		// byte-identical (non-ID integers are never remapped).
		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$metaTable,
			array(
				'comment_id' => 11,
				'meta_key' => 'votes',
				'meta_value' => 'a:1:{i:0;i:42;}',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 12,
				'comment_post_ID' => 101,
				'comment_author' => 'Bob',
				'comment_content' => 'Reply',
				'comment_approved' => '0',
				'comment_parent' => 11,
				'user_id' => 0,
			)
		);
		WpTestSchema::insertCommentMeta(
			$this->source->pdo(),
			$metaTable,
			array(
				'comment_id' => 12,
				'meta_key' => 'akismet_history',
				'meta_value' => 'a:1:{i:0;a:2:{s:10:"comment_id";i:11;s:7:"user_id";i:1;}}',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 13,
				'comment_post_ID' => 501,
				'comment_author' => 'Carol',
				'comment_content' => 'On attachment',
				'comment_approved' => '1',
				'user_id' => 2,
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 14,
				'comment_post_ID' => 101,
				'comment_author' => 'Dave',
				'comment_content' => 'Unmapped user',
				'comment_approved' => '1',
				'user_id' => 3,
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 15,
				'comment_post_ID' => 199,
				'comment_author' => 'Eve',
				'comment_content' => 'Orphaned post',
				'comment_approved' => '1',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 16,
				'comment_post_ID' => 101,
				'comment_author' => 'Spammer',
				'comment_content' => 'Buy now',
				'comment_approved' => 'spam',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 17,
				'comment_post_ID' => 101,
				'comment_author' => 'Trashed',
				'comment_content' => 'Deleted',
				'comment_approved' => 'trash',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 18,
				'comment_post_ID' => 101,
				'comment_author' => 'Frank',
				'comment_content' => 'Reply to spam',
				'comment_approved' => '1',
				'comment_parent' => 16,
			)
		);
	}

	/**
	 * Site 7: 21 approved guest + 22 approved by user 1, a threaded
	 * reply to 21 (cross-site second-pass parent fixup).
	 */
	private function seedSiteSevenComments(): void {
		$commentsTable = $this->source->siteTable( 'comments', 7 );

		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 21,
				'comment_post_ID' => 201,
				'comment_author' => 'Grace',
				'comment_content' => 'Seven first',
				'comment_approved' => '1',
			)
		);
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$commentsTable,
			array(
				'comment_ID' => 22,
				'comment_post_ID' => 201,
				'comment_author' => 'Heidi',
				'comment_content' => 'Seven reply',
				'comment_approved' => '1',
				'comment_parent' => 21,
				'user_id' => 1,
			)
		);
	}

	public function testMigratesCommentsRewritesPostUserAndMeta(): void {
		$this->seedSiteOneComments();
		$this->seedSiteSevenComments();

		$report = $this->runMigrate( dryRun: false );

		// Site 1: 11, 12, 13, 14, 18 migrate (15 dropped, 16/17
		// discarded); site 7: 21 + 22.
		self::assertSame( 7, $report['comments_migrated'] );
		self::assertSame( 0, $report['comments_failed'] );
		self::assertSame( 7, $this->destinationRowCount( $this->destination->siteTable( 'comments', 1 ) ) );
		self::assertSame( 5, $report['by_site'][1]['comments'] );
		self::assertSame( 2, $report['by_site'][7]['comments'] );

		$byContent = $this->destinationCommentsByContent();

		// comment_post_ID rewritten through the posts IdMap.
		self::assertSame( 201, (int) $byContent['First']['comment_post_ID'] );
		// Registered user remapped (1 -> 10); guest stays 0.
		self::assertSame( 10, (int) $byContent['First']['user_id'] );
		self::assertSame( 0, (int) $byContent['Reply']['user_id'] );
		// Attachment comments resolve through the attachments IdMap.
		self::assertSame( 901, (int) $byContent['On attachment']['comment_post_ID'] );
		// Cross-site post remap.
		self::assertSame( 301, (int) $byContent['Seven first']['comment_post_ID'] );
		// Author data copied verbatim.
		self::assertSame( 'Alice', (string) $byContent['First']['comment_author'] );
		self::assertSame( '1', (string) $byContent['First']['comment_approved'] );
		self::assertSame( '0', (string) $byContent['Reply']['comment_approved'] );

		// Meta copied; the serialized akismet_history has its
		// comment_id/user_id remapped with correct byte lengths, while
		// non-ID integers pass through untouched.
		self::assertSame( 3, $report['meta_rows_written'] );
		self::assertSame( '5', $this->commentMetaValue( (int) $byContent['First']['comment_ID'], 'rating' ) );
		self::assertSame( 'a:1:{i:0;i:42;}', $this->commentMetaValue( (int) $byContent['First']['comment_ID'], 'votes' ) );
		$firstId = (int) $byContent['First']['comment_ID'];
		self::assertSame(
			sprintf( 'a:1:{i:0;a:2:{s:10:"comment_id";i:%d;s:7:"user_id";i:10;}}', $firstId ),
			$this->commentMetaValue( (int) $byContent['Reply']['comment_ID'], 'akismet_history' )
		);
	}

	public function testThreadedRepliesResolveInSecondPass(): void {
		$this->seedSiteOneComments();
		$this->seedSiteSevenComments();

		$report = $this->runMigrate( dryRun: false );

		$byContent = $this->destinationCommentsByContent();

		self::assertSame( (int) $byContent['First']['comment_ID'], (int) $byContent['Reply']['comment_parent'] );
		self::assertSame( (int) $byContent['Seven first']['comment_ID'], (int) $byContent['Seven reply']['comment_parent'] );
		self::assertSame( 2, $report['parents_fixed'] );
	}

	public function testSpamAndTrashAreDiscardedButCounted(): void {
		$this->seedSiteOneComments();

		$report = $this->runMigrate( dryRun: false );

		$byContent = $this->destinationCommentsByContent();

		self::assertSame( 1, $report['spam_skipped'] );
		self::assertSame( 1, $report['trash_skipped'] );
		self::assertArrayNotHasKey( 'Buy now', $byContent );
		self::assertArrayNotHasKey( 'Deleted', $byContent );
	}

	public function testCommentsOnUnmigratedPostsAreDroppedAndCounted(): void {
		$this->seedSiteOneComments();

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 1, $report['comments_post_unmapped'] );
		self::assertArrayNotHasKey( 'Orphaned post', $this->destinationCommentsByContent() );
		self::assertNotEmpty(
			array_filter(
				$report['warnings'],
				static fn ( string $warning ): bool => str_contains( $warning, 'post 199 was not migrated' )
			)
		);
	}

	public function testUnmappedRegisteredUserFallsBackToGuest(): void {
		$this->seedSiteOneComments();

		$report = $this->runMigrate( dryRun: false );

		$byContent = $this->destinationCommentsByContent();

		self::assertSame( 1, $report['user_id_fallback'] );
		self::assertSame( 0, (int) $byContent['Unmapped user']['user_id'] );
	}

	public function testUnresolvedParentLeftZeroAndWarned(): void {
		$this->seedSiteOneComments();

		$report = $this->runMigrate( dryRun: false );

		$byContent = $this->destinationCommentsByContent();

		// Reply to a spam comment: the parent did not migrate, so the
		// reply is top-level (0), counted and warned.
		self::assertSame( 1, $report['parents_unresolved'] );
		self::assertSame( 0, (int) $byContent['Reply to spam']['comment_parent'] );
		self::assertNotEmpty(
			array_filter(
				$report['warnings'],
				static fn ( string $warning ): bool => str_contains( $warning, 'parent comment 16 was not migrated' )
			)
		);
	}

	public function testUnexpectedApprovedValueIsSkippedAndWarned(): void {
		$this->seedSiteOneComments();
		WpTestSchema::insertComment(
			$this->source->pdo(),
			$this->source->siteTable( 'comments', 1 ),
			array(
				'comment_ID' => 19,
				'comment_post_ID' => 101,
				'comment_author' => 'Legacy',
				'comment_content' => 'Hold',
				'comment_approved' => 'hold',
			)
		);

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 1, $report['comments_skipped'] );
		self::assertArrayNotHasKey( 'Hold', $this->destinationCommentsByContent() );
		self::assertNotEmpty(
			array_filter(
				$report['warnings'],
				static fn ( string $warning ): bool => str_contains( $warning, 'comment_approved value(s) (hold)' )
			)
		);
	}

	public function testDryRunWritesNothing(): void {
		$this->seedSiteOneComments();
		$this->seedSiteSevenComments();

		$report = $this->runMigrate( dryRun: true );

		// The plan still shows the drops so it can be reviewed.
		self::assertSame( 7, $report['comments_migrated'] );
		self::assertSame( 1, $report['comments_post_unmapped'] );
		self::assertSame( 1, $report['spam_skipped'] );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'comments', 1 ) ) );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'commentmeta', 1 ) ) );
	}

	public function testRerunSkipsAlreadyMigratedComments(): void {
		$this->seedSiteOneComments();
		$this->seedSiteSevenComments();
		$this->runMigrate( dryRun: false );

		$second = $this->runMigrate( dryRun: false );

		self::assertSame( 7, $second['comments_skipped'] );
		self::assertSame( 0, $second['comments_migrated'] );
		self::assertSame( 7, $this->destinationRowCount( $this->destination->siteTable( 'comments', 1 ) ) );
	}

	/**
	 * @return array{
	 *     comments_migrated: int, comments_skipped: int, comments_failed: int,
	 *     comments_post_unmapped: int, meta_rows_written: int, parents_fixed: int,
	 *     parents_unresolved: int, user_id_fallback: int, spam_skipped: int,
	 *     trash_skipped: int, warnings: string[], by_site: array<int, array{comments:int}>
	 * }
	 */
	private function runMigrate( bool $dryRun, array $configOverrides = array() ): array {
		// Prewarm from the map table exactly like bin/migrate.php so a
		// rerun skips already-migrated rows.
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );
		foreach ( array( 'user', 'post', 'attachment' ) as $type ) {
			foreach ( $this->idMap->entries( $type ) as $key => $value ) {
				[ $siteId, $oldId ] = explode( ':', $key, 2 );
				$idMap->set( $type, (int) $siteId, is_numeric( $oldId ) ? (int) $oldId : $oldId, $value );
			}
		}

		return ( new CommentMigrator() )->migrate(
			$this->source,
			$this->destination,
			$this->testConfig( overrides: $configOverrides ),
			array(
				new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ),
				new Site( blogId: 7, domain: 'seven.example.test', path: '/', title: 'Seven', deleted: false, included: true ),
			),
			$idMap,
			$this->mapTable,
			$dryRun,
			new Logger( 'error' )
		);
	}

	/**
	 * @return array<string, array<string, mixed>> comment_content => row.
	 */
	private function destinationCommentsByContent(): array {
		$rows = $this->destination->fetchAll(
			"SELECT * FROM {$this->destination->siteTable( 'comments', 1 )}"
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['comment_content'] ] = $row;
		}

		return $map;
	}

	private function commentMetaValue( int $commentId, string $key ): string {
		return (string) $this->destination->fetchScalar(
			"SELECT meta_value FROM {$this->destination->siteTable( 'commentmeta', 1 )} WHERE comment_id = :id AND meta_key = :key",
			array(
				'id' => $commentId,
				'key' => $key,
			)
		);
	}

	private function destinationRowCount( string $table ): int {
		return (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$table}" );
	}
}
