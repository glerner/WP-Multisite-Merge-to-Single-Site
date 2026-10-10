<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\PostMigrator;
use MergeMultisite\Migration\Site;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * PostMigrator end-to-end on SQLite: posts/pages/CPTs + postmeta +
 * term_relationships + site categories, two-pass parent fixups,
 * destination slug dedup + contact canonicalization, _thumbnail_id and
 * block-attribute remapping, trash discarding + future flagging,
 * dry-run writing
 * nothing, and resume idempotency.
 *
 * The IdMap is seeded the way the earlier phases (users, terms, media)
 * leave it, so this test isolates PostMigrator's own behaviour.
 *
 * @package MergeMultisite
 */
#[CoversClass( PostMigrator::class )]
final class PostMigratorTest extends SqliteTestCase {

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
	 * What Users/Terms/Media leave in the IdMap for this fixture.
	 */
	private function seedEarlierPhases(): void {
		$this->idMap->set( 'user', 0, 1, 10 );

		// Source term_taxonomy rows the relationship JOIN needs: site 1
		// 'News' (tt 1 -> term 1) and site 7 'News' (tt 11 -> term 11).
		$sourceTaxonomy = $this->source->siteTable( 'term_taxonomy', 1 );
		$this->source->execute(
			"INSERT INTO {$sourceTaxonomy} (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (1, 1, 'category', '', 0, 0)"
		);
		$sourceTaxonomy7 = $this->source->siteTable( 'term_taxonomy', 7 );
		$this->source->execute(
			"INSERT INTO {$sourceTaxonomy7} (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (11, 11, 'category', '', 0, 0)"
		);

		// Site 1: 'News' category (source term 1 -> dest tt 11) and the
		// site category (dest tt 50).
		$this->idMap->set( 'term', 1, 1, 100 );
		$this->idMap->set( 'term_taxonomy', 1, 1, 11 );
		$this->idMap->set( 'site_category', 1, 'category', 50 );

		// Site 7: 'News' (term 11 -> dest tt 12) + site category 51.
		$this->idMap->set( 'term', 7, 11, 101 );
		$this->idMap->set( 'term_taxonomy', 7, 11, 12 );
		$this->idMap->set( 'site_category', 7, 'category', 51 );

		// Attachment 501 on site 1 migrated to destination post 900.
		$this->idMap->set( 'attachment', 1, 501, 900 );

		// Destination term_taxonomy rows the mappings point at.
		$destTaxonomy = $this->destination->siteTable( 'term_taxonomy', 1 );
		foreach (
			array(
				array(
		'term_taxonomy_id' => 11,
		'term_id' => 100,
		'taxonomy' => 'category',
			),
				array(
			'term_taxonomy_id' => 12,
			'term_id' => 101,
			'taxonomy' => 'category',
			),
				array(
			'term_taxonomy_id' => 50,
			'term_id' => 500,
			'taxonomy' => 'category',
			),
				array(
			'term_taxonomy_id' => 51,
			'term_id' => 501,
			'taxonomy' => 'category',
			),
			) as $row
		) {
			$this->destination->execute(
				"INSERT INTO {$destTaxonomy} (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (:tt_id, :term_id, :taxonomy, '', 0, 0)",
				array(
					'tt_id' => $row['term_taxonomy_id'],
					'term_id' => $row['term_id'],
					'taxonomy' => $row['taxonomy'],
				)
			);
		}
	}

	public function testMigratesPostsMetaRelationshipsAndSiteCategories(): void {
		$this->seedSiteOnePosts();
		$this->seedSiteSevenPosts();

		$report = $this->runMigrate( dryRun: false );

		// 8 = site 1's 6 seeded posts minus the trashed one + site 7's 3.
		self::assertSame( 8, $report['posts_migrated'] );
		self::assertSame( 0, $report['posts_failed'] );
		self::assertSame( 8, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );

		$bySlug = $this->destinationPostsBySlug();

		// Slugs: dedup across sites + contact canonicalization.
		self::assertSame( 'about', $bySlug['about']['post_name'] );
		self::assertSame( 'about-2', $bySlug['about-2']['post_name'] );
		self::assertSame( 'contact', $bySlug['contact']['post_name'] );
		self::assertSame( 'contact-2', $bySlug['contact-2']['post_name'] );

		// Report records the canonicalizations and the plain rename.
		self::assertCount( 2, $report['contact_canonicalized'] );
		self::assertCount( 1, $report['slug_renames'] );
		self::assertSame( 'about-2', $report['slug_renames'][0]['new_slug'] );

		// GUID regenerated against the destination URL + new ID.
		$about = $bySlug['about'];
		self::assertSame( 'https://example.test/?p=' . $about['ID'], $about['guid'] );

		// Author remapped (user 1 -> destination 10).
		self::assertSame( 10, (int) $bySlug['hello-world']['post_author'] );
	}

	public function testRemapsThumbnailsAndBlockAttributeReferences(): void {
		$this->seedSiteOnePosts();

		$this->runMigrate( dryRun: false );

		$bySlug = $this->destinationPostsBySlug();

		// _thumbnail_id 501 (source attachment) -> 900 (destination).
		self::assertSame( '900', $this->postMetaValue( (int) $bySlug['hello-world']['ID'], '_thumbnail_id' ) );

		// wp:image {"id":501} in post_content -> 900.
		self::assertSame( '<!-- wp:image {"id":900} /-->', $bySlug['about']['post_content'] );
	}

	public function testParentFixupResolvesAcrossBatches(): void {
		$this->seedSiteOnePosts();

		$report = $this->runMigrate( dryRun: false );

		$bySlug = $this->destinationPostsBySlug();

		// Child's parent is the 'about' page, which migrated in an
		// earlier batch; the second pass must resolve it.
		self::assertSame( (int) $bySlug['about']['ID'], (int) $bySlug['child']['post_parent'] );
		self::assertSame( 1, $report['parents_fixed'] );
	}

	public function testTrashIsDiscardedAndFuturePostsAreFlagged(): void {
		$this->seedSiteOnePosts();

		$report = $this->runMigrate( dryRun: false );

		$bySlug = $this->destinationPostsBySlug();

		// Trashed posts are deleted content: never migrated, but the
		// discard is counted so the report stays honest.
		self::assertArrayNotHasKey( 'trashy', $bySlug );
		self::assertSame( 1, $report['trash_skipped'] );

		self::assertSame( 'future', $bySlug['future-post']['post_status'] );
		self::assertCount( 1, $report['future_posts'] );
		self::assertSame( 104, $report['future_posts'][0]['post_id'] );
		self::assertSame( '2027-01-01 00:00:00', $report['future_posts'][0]['date_gmt'] );
	}

	public function testTermCountsRefreshPerSite(): void {
		$this->seedSiteOnePosts();
		$this->seedSiteSevenPosts();

		$this->runMigrate( dryRun: false );

		// Site 1 'News' (tt 11): hello-world + child both carry it.
		self::assertSame( 2, (int) $this->destination->fetchScalar( "SELECT count FROM {$this->destination->siteTable( 'term_taxonomy', 1 )} WHERE term_taxonomy_id = 11" ) );
		// Site 1 site category (tt 50): every one of site 1's 5 migrated
		// posts (the trashed one is discarded).
		self::assertSame( 5, (int) $this->destination->fetchScalar( "SELECT count FROM {$this->destination->siteTable( 'term_taxonomy', 1 )} WHERE term_taxonomy_id = 50" ) );
		// Site 7 site category (tt 51): 3 posts.
		self::assertSame( 3, (int) $this->destination->fetchScalar( "SELECT count FROM {$this->destination->siteTable( 'term_taxonomy', 1 )} WHERE term_taxonomy_id = 51" ) );
	}

	public function testDryRunWritesNothing(): void {
		$this->seedSiteOnePosts();

		$report = $this->runMigrate( dryRun: true );

		self::assertSame( 5, $report['posts_migrated'] );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'postmeta', 1 ) ) );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'term_relationships', 1 ) ) );
	}

	public function testRerunSkipsAlreadyMigratedPosts(): void {
		$this->seedSiteOnePosts();
		$this->runMigrate( dryRun: false );

		$second = $this->runMigrate( dryRun: false );

		self::assertSame( 5, $second['posts_skipped'] );
		self::assertSame( 0, $second['posts_migrated'] );
		self::assertSame( 5, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
	}

	public function testAutoDraftAndTrashAreExcluded(): void {
		$this->seedSiteOnePosts();
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
			'ID' => 107,
			'post_title' => 'Draft',
			'post_name' => 'draft',
			'post_status' => 'auto-draft',
			)
		);

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 5, $report['posts_migrated'] );
		self::assertArrayNotHasKey( 'draft', $this->destinationPostsBySlug() );
		self::assertArrayNotHasKey( 'trashy', $this->destinationPostsBySlug() );
	}

	public function testAuditOnlyExclusionDoesNotBlockMigration(): void {
		$this->seedSiteOnePosts();
		// User data (e.g. a form submission CPT) suppressed from AUDIT
		// output must still migrate -- audit_excluded_post_types is not a
		// migration exclusion.
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
				'ID' => 108,
				'post_title' => 'Form entry',
				'post_name' => 'form-entry-1',
				'post_type' => 'nf_sub',
			)
		);

		$report = $this->runMigrate(
			dryRun: false,
			configOverrides: array( 'audit_excluded_post_types' => array( 'nf_sub' ) )
		);

		self::assertSame( 6, $report['posts_migrated'] );
		self::assertArrayHasKey( 'form-entry-1', $this->destinationPostsBySlug() );
	}

	public function testMigrationExclusionDoesBlockMigration(): void {
		$this->seedSiteOnePosts();
		// A junk/leftover type listed in migration_excluded_post_types
		// must NOT migrate.
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
				'ID' => 109,
				'post_title' => 'Sitemap row',
				'post_name' => 'sitemap-row-1',
				'post_type' => 'jp_sitemap',
			)
		);

		$report = $this->runMigrate(
			dryRun: false,
			configOverrides: array( 'migration_excluded_post_types' => array( 'jp_sitemap' ) )
		);

		self::assertSame( 5, $report['posts_migrated'] );
		self::assertArrayNotHasKey( 'sitemap-row-1', $this->destinationPostsBySlug() );
	}

	/**
	 * Site 1: about (page, parent 0, image block), contact-me (page,
	 * canonicalized), hello-world (post, News category, thumbnail),
	 * child (page, parent = about), future-post, trashy.
	 */
	private function seedSiteOnePosts(): void {
		$postsTable = $this->source->siteTable( 'posts', 1 );
		$postMetaTable = $this->source->siteTable( 'postmeta', 1 );
		$relationshipsTable = $this->source->siteTable( 'term_relationships', 1 );

		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 101,
				'post_title' => 'About Us',
				'post_name' => 'about',
				'post_type' => 'page',
				'post_author' => 1,
				'post_content' => '<!-- wp:image {"id":501} /-->',
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 102,
				'post_title' => 'Contact Me',
				'post_name' => 'contact-me',
				'post_type' => 'page',
			)
		);
		$helloId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 103,
				'post_title' => 'Hello World',
				'post_name' => 'hello-world',
				'post_author' => 1,
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
				'post_id' => $helloId,
				'meta_key' => '_thumbnail_id',
				'meta_value' => '501',
			)
		);
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$relationshipsTable,
			array(
				'object_id' => $helloId,
				'term_taxonomy_id' => 1, // source tt of 'News' (term_id 1).
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 106,
				'post_title' => 'Child',
				'post_name' => 'child',
				'post_type' => 'page',
				'post_parent' => 101,
			)
		);
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$relationshipsTable,
			array(
				'object_id' => 106,
				'term_taxonomy_id' => 1, // Child is in 'News' too.
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 104,
				'post_title' => 'Scheduled',
				'post_name' => 'future-post',
				'post_status' => 'future',
				'post_date' => '2027-01-01 00:00:00',
				'post_date_gmt' => '2027-01-01 00:00:00',
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 105,
				'post_title' => 'Trashed',
				'post_name' => 'trashy',
				'post_status' => 'trash',
			)
		);
	}

	/**
	 * Site 7: about (page, collides with site 1's 'about'),
	 * contact (page, canonicalizes and collides with 'contact'),
	 * another (post, News category).
	 */
	private function seedSiteSevenPosts(): void {
		$postsTable = $this->source->siteTable( 'posts', 7 );
		$relationshipsTable = $this->source->siteTable( 'term_relationships', 7 );

		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 201,
				'post_title' => 'About',
				'post_name' => 'about',
				'post_type' => 'page',
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 202,
				'post_title' => 'Contact',
				'post_name' => 'contact',
				'post_type' => 'page',
			)
		);
		$anotherId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 203,
				'post_title' => 'Another',
				'post_name' => 'another',
			)
		);
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$relationshipsTable,
			array(
				'object_id' => $anotherId,
				'term_taxonomy_id' => 11, // source tt of site 7's 'News' (term_id 11).
			)
		);
	}

	/**
	 * @return array{posts_migrated: int, posts_skipped: int, posts_failed: int, meta_rows_written: int, relationships_written: int, relationships_unmapped: int, site_category_assignments: int, parents_fixed: int, parents_unresolved: int, authors_fallback: int, thumbnails_unmapped: int, slug_renames: array<int, array<string, mixed>>, contact_canonicalized: array<int, array<string, mixed>>, trash_skipped: int, future_posts: array<int, array<string, mixed>>, warnings: string[], by_site: array<int, array{posts:int}>}
	 */
	private function runMigrate( bool $dryRun, array $configOverrides = array() ): array {
		// Prewarm from the map table exactly like bin/migrate.php so a
		// rerun skips already-migrated rows.
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );
		foreach ( array( 'user', 'term', 'term_taxonomy', 'site_category', 'attachment' ) as $type ) {
			foreach ( $this->idMap->entries( $type ) as $key => $value ) {
				[ $siteId, $oldId ] = explode( ':', $key, 2 );
				$idMap->set( $type, (int) $siteId, is_numeric( $oldId ) ? (int) $oldId : $oldId, $value );
			}
		}

		return ( new PostMigrator() )->migrate(
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
	 * @return array<string, array<string, mixed>> post_name => row.
	 */
	private function destinationPostsBySlug(): array {
		$rows = $this->destination->fetchAll(
			"SELECT * FROM {$this->destination->siteTable( 'posts', 1 )}"
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['post_name'] ] = $row;
		}

		return $map;
	}

	private function postMetaValue( int $postId, string $key ): string {
		return (string) $this->destination->fetchScalar(
			"SELECT meta_value FROM {$this->destination->siteTable( 'postmeta', 1 )} WHERE post_id = :id AND meta_key = :key",
			array(
				'id' => $postId,
				'key' => $key,
			)
		);
	}

	private function destinationRowCount( string $table ): int {
		return (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$table}" );
	}
}
