<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MenuMigrator;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\Site;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * MenuMigrator end-to-end on SQLite: nav_menu term creation, item +
 * meta copying with _menu_item_object_id remapping by type, two-pass
 * item-parent fixups, term relationships (incl. orphans), wp_navigation
 * block-attribute remapping, trash item discarding, the overview tree,
 * dry-run writing nothing, and resume idempotency.
 *
 * The IdMap is seeded the way the earlier phases leave it; the
 * destination posts table is left empty so inserted item IDs are
 * predictable (1..N in insertion order).
 *
 * @package MergeMultisite
 */
#[CoversClass( MenuMigrator::class )]
final class MenuMigratorTest extends SqliteTestCase {

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
	 * What Users/Posts/Terms leave in the IdMap for this fixture.
	 */
	private function seedEarlierPhases(): void {
		$this->idMap->set( 'user', 0, 1, 10 );

		// Site 1: page targets for menu items + an attachment.
		$this->idMap->set( 'post', 1, 101, 201 );
		$this->idMap->set( 'post', 1, 102, 202 );
		$this->idMap->set( 'attachment', 1, 501, 901 );
		// Site 1: a category (term_id 5) a taxonomy menu item links to.
		$this->idMap->set( 'term', 1, 5, 100 );

		// Site 7: a page target.
		$this->idMap->set( 'post', 7, 201, 301 );
	}

	/**
	 * Site 1: one "Main Menu" (nav_menu term) with four items — a page
	 * link, its child, a category link, a custom URL — plus an
	 * orphaned item and a trashed item, and two wp_navigation posts
	 * (one embedding an attachment image block, one referencing the
	 * other via a navigation ref block).
	 */
	private function seedSiteOneMenus(): void {
		$termsTable = $this->source->siteTable( 'terms', 1 );
		$taxonomyTable = $this->source->siteTable( 'term_taxonomy', 1 );
		$postsTable = $this->source->siteTable( 'posts', 1 );
		$metaTable = $this->source->siteTable( 'postmeta', 1 );
		$relationshipsTable = $this->source->siteTable( 'term_relationships', 1 );

		$menuTermId = WpTestSchema::insertTerm(
			$this->source->pdo(),
			$termsTable,
			array(
			'name' => 'Main Menu',
			'slug' => 'main-menu',
			)
		);
		$menuTtId = WpTestSchema::insertTermTaxonomy(
			$this->source->pdo(),
			$taxonomyTable,
			array(
			'term_id' => $menuTermId,
			'taxonomy' => 'nav_menu',
			)
		);

		$itemIds = array();
		foreach (
			array(
				11 => array(
					'title' => 'About',
					'menu_order' => 1,
					'meta' => array(
						'_menu_item_type' => 'post_type',
						'_menu_item_object' => 'page',
						'_menu_item_object_id' => '101',
						'_menu_item_url' => '',
						'_menu_item_menu_item_parent' => '0',
					),
				),
				12 => array(
					'title' => 'Child',
					'menu_order' => 2,
					'meta' => array(
						'_menu_item_type' => 'post_type',
						'_menu_item_object' => 'page',
						'_menu_item_object_id' => '102',
						'_menu_item_url' => '',
						'_menu_item_menu_item_parent' => '11',
					),
				),
				13 => array(
					'title' => 'Category link',
					'menu_order' => 3,
					'meta' => array(
						'_menu_item_type' => 'taxonomy',
						'_menu_item_object' => 'category',
						'_menu_item_object_id' => '5',
						'_menu_item_url' => '',
						'_menu_item_menu_item_parent' => '0',
					),
				),
				14 => array(
					'title' => 'External',
					'menu_order' => 4,
					'meta' => array(
						'_menu_item_type' => 'custom',
						'_menu_item_object' => 'custom',
						'_menu_item_object_id' => '0',
						'_menu_item_url' => 'https://example.test/x',
						'_menu_item_menu_item_parent' => '0',
					),
				),
			) as $id => $spec
		) {
			$itemIds[ $id ] = WpTestSchema::insertPost(
				$this->source->pdo(),
				$postsTable,
				array(
					'ID' => $id,
					'post_type' => 'nav_menu_item',
					'post_title' => $spec['title'],
					'menu_order' => $spec['menu_order'],
					'post_author' => 1,
				)
			);
			foreach ( $spec['meta'] as $key => $value ) {
				WpTestSchema::insertPostMeta(
					$this->source->pdo(),
					$metaTable,
					array(
					'post_id' => $itemIds[ $id ],
					'meta_key' => $key,
					'meta_value' => $value,
					)
				);
			}
			WpTestSchema::insertTermRelationship(
				$this->source->pdo(),
				$relationshipsTable,
				array(
				'object_id' => $itemIds[ $id ],
				'term_taxonomy_id' => $menuTtId,
				)
			);
		}

		// Orphaned (unassigned) item: no nav_menu relationship.
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 15,
				'post_type' => 'nav_menu_item',
				'post_title' => 'Orphan',
				'menu_order' => 1,
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$metaTable,
			array(
			'post_id' => 15,
			'meta_key' => '_menu_item_type',
			'meta_value' => 'custom',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$metaTable,
			array(
			'post_id' => 15,
			'meta_key' => '_menu_item_url',
			'meta_value' => 'https://example.test/y',
			)
		);

		// Trashed item: deleted content, discarded (counted).
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 16,
				'post_type' => 'nav_menu_item',
				'post_title' => 'Trashed',
				'post_status' => 'trash',
			)
		);

		// wp_navigation posts: one embedding an image block, one
		// referencing the first via a navigation ref block.
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 21,
				'post_type' => 'wp_navigation',
				'post_title' => 'Header nav',
				'post_content' => '<!-- wp:image {"id":501} /-->',
			)
		);
		WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 22,
				'post_type' => 'wp_navigation',
				'post_title' => 'Footer nav',
				'post_content' => '<!-- wp:navigation {"ref":21} /-->',
			)
		);
	}

	/**
	 * Site 7: one "Seven Menu" with a single page link.
	 */
	private function seedSiteSevenMenus(): void {
		$termsTable = $this->source->siteTable( 'terms', 7 );
		$taxonomyTable = $this->source->siteTable( 'term_taxonomy', 7 );
		$postsTable = $this->source->siteTable( 'posts', 7 );
		$metaTable = $this->source->siteTable( 'postmeta', 7 );
		$relationshipsTable = $this->source->siteTable( 'term_relationships', 7 );

		$menuTermId = WpTestSchema::insertTerm(
			$this->source->pdo(),
			$termsTable,
			array(
			'name' => 'Seven Menu',
			'slug' => 'seven-menu',
			)
		);
		$menuTtId = WpTestSchema::insertTermTaxonomy(
			$this->source->pdo(),
			$taxonomyTable,
			array(
			'term_id' => $menuTermId,
			'taxonomy' => 'nav_menu',
			)
		);

		$itemId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$postsTable,
			array(
				'ID' => 31,
				'post_type' => 'nav_menu_item',
				'post_title' => 'Seven About',
				'menu_order' => 1,
			)
		);
		foreach (
			array(
				'_menu_item_type' => 'post_type',
				'_menu_item_object' => 'page',
				'_menu_item_object_id' => '201',
				'_menu_item_url' => '',
				'_menu_item_menu_item_parent' => '0',
			) as $key => $value
		) {
			WpTestSchema::insertPostMeta(
				$this->source->pdo(),
				$metaTable,
				array(
				'post_id' => $itemId,
				'meta_key' => $key,
				'meta_value' => $value,
				)
			);
		}
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$relationshipsTable,
			array(
			'object_id' => $itemId,
			'term_taxonomy_id' => $menuTtId,
			)
		);
	}

	public function testMigratesMenusItemsAndNavigationPosts(): void {
		$this->seedSiteOneMenus();
		$this->seedSiteSevenMenus();

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 2, $report['menus_migrated'] );
		self::assertSame( 6, $report['menu_items_migrated'] );
		self::assertSame( 1, $report['items_trash_skipped'] );
		self::assertSame( 0, $report['menu_items_failed'] );
		self::assertSame( 0, $report['item_targets_unmapped'] );
		self::assertSame( 2, $report['nav_posts_migrated'] );
		self::assertSame( 5, $report['relationships_written'] );
		self::assertSame( 1, $report['parents_fixed'] );

		// Destination: 2 nav_menu terms, 8 posts (6 items + 2 nav).
		$destPosts = $this->destination->siteTable( 'posts', 1 );
		$destTerms = $this->destination->siteTable( 'terms', 1 );
		$destTaxonomy = $this->destination->siteTable( 'term_taxonomy', 1 );
		self::assertSame( 2, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$destTerms}" ) );
		self::assertSame( 2, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$destTaxonomy} WHERE taxonomy = 'nav_menu'" ) );
		self::assertSame( 8, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$destPosts}" ) );

		$byTitle = $this->destinationPostsByTitle();

		// Item targets remapped by type.
		self::assertSame( '201', $this->itemMeta( (int) $byTitle['About']['ID'], '_menu_item_object_id' ) );
		self::assertSame( '202', $this->itemMeta( (int) $byTitle['Child']['ID'], '_menu_item_object_id' ) );
		self::assertSame( '100', $this->itemMeta( (int) $byTitle['Category link']['ID'], '_menu_item_object_id' ) );
		self::assertSame( '0', $this->itemMeta( (int) $byTitle['External']['ID'], '_menu_item_object_id' ) );
		self::assertSame( '301', $this->itemMeta( (int) $byTitle['Seven About']['ID'], '_menu_item_object_id' ) );

		// Second-pass parent fixup: Child's parent is About's NEW id (1).
		self::assertSame( (string) $byTitle['About']['ID'], $this->itemMeta( (int) $byTitle['Child']['ID'], '_menu_item_menu_item_parent' ) );

		// wp_navigation block attributes remapped.
		self::assertSame( '<!-- wp:image {"id":901} /-->', (string) $byTitle['Header nav']['post_content'] );
		self::assertSame( '<!-- wp:navigation {"ref":' . $byTitle['Header nav']['ID'] . '} /-->', (string) $byTitle['Footer nav']['post_content'] );

		// Menu term counts refreshed (site 1's menu holds 4 items).
		$site1MenuTt = (int) $this->destination->fetchScalar( "SELECT term_taxonomy_id FROM {$destTaxonomy} WHERE taxonomy = 'nav_menu' ORDER BY term_taxonomy_id LIMIT 1" );
		self::assertSame( 4, (int) $this->destination->fetchScalar( "SELECT count FROM {$destTaxonomy} WHERE term_taxonomy_id = :tt", array( 'tt' => $site1MenuTt ) ) );
	}

	public function testOrphanedItemsMigrateWithoutRelationship(): void {
		$this->seedSiteOneMenus();

		$this->runMigrate( dryRun: false );

		$byTitle = $this->destinationPostsByTitle();
		$orphanId = (int) $byTitle['Orphan']['ID'];

		$relationships = $this->destination->fetchAll(
			"SELECT term_taxonomy_id FROM {$this->destination->siteTable( 'term_relationships', 1 )} WHERE object_id = :id",
			array( 'id' => $orphanId )
		);
		self::assertCount( 0, $relationships );
		self::assertSame( 'custom', $this->itemMeta( $orphanId, '_menu_item_type' ) );
	}

	public function testOverviewTreeIsCollectedPerSite(): void {
		$this->seedSiteOneMenus();
		$this->seedSiteSevenMenus();

		$report = $this->runMigrate( dryRun: false );

		self::assertArrayHasKey( 1, $report['overview'] );
		self::assertArrayHasKey( 7, $report['overview'] );

		$site1 = $report['overview'][1];
		self::assertSame( 'main.example.test', $site1['domain'] );
		self::assertCount( 1, $site1['menus'] );
		self::assertSame( 'Main Menu', $site1['menus'][0]['name'] );
		self::assertCount( 4, $site1['menus'][0]['items'] );
		self::assertSame( 'post #101', $site1['menus'][0]['items'][0]['target'] );
		self::assertSame( 'https://example.test/x', $site1['menus'][0]['items'][3]['target'] );
		self::assertCount( 1, $site1['orphaned_items'] );
		self::assertSame( 'https://example.test/y', $site1['orphaned_items'][0]['target'] );
	}

	public function testDryRunWritesNothing(): void {
		$this->seedSiteOneMenus();
		$this->seedSiteSevenMenus();

		$report = $this->runMigrate( dryRun: true );

		self::assertSame( 2, $report['menus_migrated'] );
		self::assertSame( 6, $report['menu_items_migrated'] );
		self::assertSame( 2, $report['nav_posts_migrated'] );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'terms', 1 ) ) );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'term_relationships', 1 ) ) );
	}

	public function testRerunSkipsAlreadyMigratedMenus(): void {
		$this->seedSiteOneMenus();
		$this->seedSiteSevenMenus();
		$this->runMigrate( dryRun: false );

		$second = $this->runMigrate( dryRun: false );

		self::assertSame( 2, $second['menus_skipped'] );
		self::assertSame( 6, $second['menu_items_skipped'] );
		self::assertSame( 2, $second['nav_posts_skipped'] );
		self::assertSame( 0, $second['menu_items_migrated'] );
		self::assertSame( 8, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
	}

	/**
	 * @return array{
	 *     menus_migrated: int, menus_skipped: int, menu_items_migrated: int,
	 *     menu_items_skipped: int, menu_items_failed: int, items_trash_skipped: int,
	 *     item_targets_unmapped: int, nav_posts_migrated: int, nav_posts_skipped: int,
	 *     nav_posts_failed: int, relationships_written: int, parents_fixed: int,
	 *     parents_unresolved: int, authors_fallback: int, warnings: string[],
	 *     by_site: array<int, array{menus:int, items:int}>,
	 *     overview: array<int, array<string, mixed>>
	 * }
	 */
	private function runMigrate( bool $dryRun ): array {
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );
		foreach ( array( 'user', 'post', 'attachment', 'term' ) as $type ) {
			foreach ( $this->idMap->entries( $type ) as $key => $value ) {
				[ $siteId, $oldId ] = explode( ':', $key, 2 );
				$idMap->set( $type, (int) $siteId, is_numeric( $oldId ) ? (int) $oldId : $oldId, $value );
			}
		}

		return ( new MenuMigrator() )->migrate(
			$this->source,
			$this->destination,
			$this->testConfig(),
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
	 * @return array<string, array<string, mixed>> post_title => row.
	 */
	private function destinationPostsByTitle(): array {
		$rows = $this->destination->fetchAll(
			"SELECT * FROM {$this->destination->siteTable( 'posts', 1 )}"
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['post_title'] ] = $row;
		}

		return $map;
	}

	private function itemMeta( int $postId, string $key ): string {
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
