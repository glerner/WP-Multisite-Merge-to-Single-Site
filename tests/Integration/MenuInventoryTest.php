<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\MenuInventory;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: MenuInventory::collect() against a real (SQLite) database —
 * exercises the terms/taxonomy JOIN, the `_menu_item_*` meta LEFT
 * JOINs, and the correlated subquery that resolves an item's nav_menu
 * without duplicating rows.
 *
 * @package MergeMultisite
 */
#[CoversClass( MenuInventory::class )]
final class MenuInventoryTest extends SqliteTestCase {

	private Connection $source;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->sqliteConnection( 'wp_', array( 1 ) );

		// The menu itself: a nav_menu term + taxonomy row.
		$termId = WpTestSchema::insertTerm(
			$this->source->pdo(),
			$this->source->siteTable( 'terms', 1 ),
			array(
			'name' => 'Main Menu',
			'slug' => 'main-menu',
			)
		);
		$ttId = WpTestSchema::insertTermTaxonomy(
			$this->source->pdo(),
			$this->source->siteTable( 'term_taxonomy', 1 ),
			array(
			'term_id' => $termId,
			'taxonomy' => 'nav_menu',
			)
		);

		// A second term the menu item ALSO relates to (e.g. a category):
		// the correlated subquery must still yield exactly one nav_menu row.
		$catTermId = WpTestSchema::insertTerm(
			$this->source->pdo(),
			$this->source->siteTable( 'terms', 1 ),
			array(
			'name' => 'News',
			'slug' => 'news',
			)
		);
		$catTtId = WpTestSchema::insertTermTaxonomy(
			$this->source->pdo(),
			$this->source->siteTable( 'term_taxonomy', 1 ),
			array(
			'term_id' => $catTermId,
			'taxonomy' => 'category',
			)
		);

		// A menu item pointing at a page, assigned to the menu.
		$itemId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
			'post_type' => 'nav_menu_item',
			'post_title' => 'About',
			'menu_order' => 1,
			)
		);
		foreach (
			array(
				'_menu_item_type' => 'post_type',
				'_menu_item_object' => 'page',
				'_menu_item_object_id' => '10',
				'_menu_item_url' => '',
				'_menu_item_menu_item_parent' => '0',
			) as $key => $value
		) {
			WpTestSchema::insertPostMeta(
				$this->source->pdo(),
				$this->source->siteTable( 'postmeta', 1 ),
				array(
				'post_id' => $itemId,
				'meta_key' => $key,
				'meta_value' => $value,
				)
			);
		}
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$this->source->siteTable( 'term_relationships', 1 ),
			array(
			'object_id' => $itemId,
			'term_taxonomy_id' => $ttId,
			)
		);
		WpTestSchema::insertTermRelationship(
			$this->source->pdo(),
			$this->source->siteTable( 'term_relationships', 1 ),
			array(
			'object_id' => $itemId,
			'term_taxonomy_id' => $catTtId,
			)
		);

		// An orphaned (unassigned) menu item with no nav_menu relationship.
		$orphanId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
			'post_type' => 'nav_menu_item',
			'post_title' => 'Orphan',
			'menu_order' => 5,
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 1 ),
			array(
			'post_id' => $orphanId,
			'meta_key' => '_menu_item_type',
			'meta_value' => 'custom',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 1 ),
			array(
			'post_id' => $orphanId,
			'meta_key' => '_menu_item_url',
			'meta_value' => 'https://example.test/x',
			)
		);
	}

	public function testCollectReturnsMenusWithTaxonomyIds(): void {
		$result = ( new MenuInventory() )->collect( $this->source, 1 );

		self::assertCount( 1, $result['menus'] );
		self::assertSame( 'Main Menu', $result['menus'][0]['name'] );
		self::assertSame( 'main-menu', $result['menus'][0]['slug'] );
		self::assertNotSame( '', (string) $result['menus'][0]['term_taxonomy_id'] );
	}

	public function testCollectResolvesMenuAssignmentWithoutDuplicatingRows(): void {
		$result = ( new MenuInventory() )->collect( $this->source, 1 );

		$about = array_values(
			array_filter(
				$result['items'],
				static fn ( array $item ): bool => $item['title'] === 'About'
			)
		);

		// Exactly one row despite the item also relating to a category.
		self::assertCount( 1, $about );
		self::assertSame( 'post_type', $about[0]['item_type'] );
		self::assertSame( 'page', $about[0]['object'] );
		self::assertSame( '10', $about[0]['object_id'] );
		self::assertSame( '0', $about[0]['item_parent'] );

		$menuTtId = (string) $result['menus'][0]['term_taxonomy_id'];
		self::assertSame( $menuTtId, (string) $about[0]['menu_term_taxonomy_id'] );
	}

	public function testCollectMarksOrphanedItemsWithNullMenu(): void {
		$result = ( new MenuInventory() )->collect( $this->source, 1 );

		$orphan = array_values(
			array_filter(
				$result['items'],
				static fn ( array $item ): bool => $item['title'] === 'Orphan'
			)
		);

		self::assertCount( 1, $orphan );
		self::assertNull( $orphan[0]['menu_term_taxonomy_id'] );
		self::assertSame( 'custom', $orphan[0]['item_type'] );
		self::assertSame( 'https://example.test/x', $orphan[0]['item_url'] );
	}

	public function testCollectOrdersItemsByMenuThenMenuOrder(): void {
		$result = ( new MenuInventory() )->collect( $this->source, 1 );

		$titles = array_map( static fn ( array $item ): string => (string) $item['title'], $result['items'] );
		// NULL menu_term_taxonomy_id sorts first in ASC order on both
		// MySQL and SQLite, so the orphaned item leads.
		self::assertSame( array( 'Orphan', 'About' ), $titles );
	}
}
