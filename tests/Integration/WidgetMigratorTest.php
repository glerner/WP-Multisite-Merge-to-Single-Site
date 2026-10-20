<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\WidgetMigrator;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * WidgetMigrator end-to-end on SQLite: widget_{type} instances copied
 * with cross-site renumbering (never clobbering the destination's own
 * widgets), the Navigation Menu widget's nav_menu term id remapped,
 * sidebars_widgets namespaced per site as inactive/orphaned data,
 * corrupt sidebars_widgets handling, dry-run writing nothing, and
 * resume idempotency.
 *
 * @package MergeMultisite
 */
#[CoversClass( WidgetMigrator::class )]
final class WidgetMigratorTest extends SqliteTestCase {

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
		// MenuMigrator recreated nav_menu term 5 (site 1) as dest 500.
		$this->idMap->set( 'nav_menu', 1, 5, 500 );

		$this->seedDestinationState();
	}

	/**
	 * The destination's own widgets + sidebars (as a fresh install
	 * might have), which the migration must preserve and extend.
	 */
	private function seedDestinationState(): void {
		$optionsTable = $this->destination->siteTable( 'options', 1 );
		WpTestSchema::insertOption(
			$this->destination->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_text',
				'option_value' => serialize(
					array(
					1 => array( 'title' => 'Dest widget' ),
					'_multiwidget' => 1,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->destination->pdo(),
			$optionsTable,
			array(
				'option_name' => 'sidebars_widgets',
				'option_value' => serialize(
					array(
					'array_version' => 3,
					'wp_inactive_widgets' => array(),
					)
				),
			)
		);
	}

	/**
	 * Site 1: a text widget, a Navigation Menu widget (nav_menu term
	 * 5), and two sidebars referencing them.
	 */
	private function seedSiteOneWidgets(): void {
		$optionsTable = $this->source->siteTable( 'options', 1 );

		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_text',
				'option_value' => serialize(
					array(
					1 => array( 'title' => 'Hello' ),
					'_multiwidget' => 1,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_nav_menu',
				'option_value' => serialize(
					array(
					2 => array(
					'title' => 'Menu',
					'nav_menu' => 5,
					 ),
					 '_multiwidget' => 1,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'sidebars_widgets',
				'option_value' => serialize(
					array(
						'array_version' => 3,
						'wp_inactive_widgets' => array(),
						'sidebar-1' => array( 'text-1' ),
						'sidebar-2' => array( 'nav_menu-2', 'text-1' ),
					)
				),
			)
		);
	}

	/**
	 * Site 7: a text widget in a sidebar AND in wp_inactive_widgets.
	 */
	private function seedSiteSevenWidgets(): void {
		$optionsTable = $this->source->siteTable( 'options', 7 );

		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_text',
				'option_value' => serialize(
					array(
					1 => array( 'title' => 'Seven text' ),
					'_multiwidget' => 1,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'sidebars_widgets',
				'option_value' => serialize(
					array(
						'array_version' => 3,
						'wp_inactive_widgets' => array( 'text-1' ),
						'sidebar-1' => array( 'text-1' ),
					)
				),
			)
		);
	}

	public function testMigratesWidgetsWithRenumberingAndNamespacedSidebars(): void {
		$this->seedSiteOneWidgets();
		$this->seedSiteSevenWidgets();

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 3, $report['instances_migrated'] );
		self::assertSame( 3, $report['widget_options_written'] );
		self::assertSame( 4, $report['sidebar_areas_written'] );
		self::assertSame( 0, $report['targets_unmapped'] );
		self::assertSame( 0, $report['instances_missing'] );

		// Destination widget_text: destination's own instance 1 kept,
		// site 1's instance renumbered to 2, site 7's to 3.
		$text = unserialize(
			(string) $this->destination->fetchScalar(
				"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'widget_text'"
			)
		);
		self::assertSame( 'Dest widget', $text[1]['title'] );
		self::assertSame( 'Hello', $text[2]['title'] );
		self::assertSame( 'Seven text', $text[3]['title'] );
		self::assertSame( 1, $text['_multiwidget'] );

		// Navigation Menu widget: nav_menu term id 5 remapped to 500.
		$navMenu = unserialize(
			(string) $this->destination->fetchScalar(
				"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'widget_nav_menu'"
			)
		);
		self::assertSame( 500, $navMenu[1]['nav_menu'] );

		// sidebars_widgets: destination's own keys preserved, source
		// areas namespaced and rewritten to the new instance ids.
		$sidebars = unserialize(
			(string) $this->destination->fetchScalar(
				"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'sidebars_widgets'"
			)
		);
		self::assertArrayHasKey( 'array_version', $sidebars );
		self::assertArrayHasKey( 'wp_inactive_widgets', $sidebars );
		self::assertSame( array( 'text-2' ), $sidebars['site1_sidebar-1'] );
		self::assertSame( array( 'nav_menu-1', 'text-2' ), $sidebars['site1_sidebar-2'] );
		self::assertSame( array( 'text-3' ), $sidebars['site7_sidebar-1'] );
		self::assertSame( array( 'text-3' ), $sidebars['site7_wp_inactive_widgets'] );
	}

	public function testCorruptSidebarsWidgetsSkipsSiteAndIsReported(): void {
		$this->seedSiteOneWidgets();
		$optionsTable = $this->source->siteTable( 'options', 1 );
		$this->source->execute(
			"UPDATE {$optionsTable} SET option_value = 'not:serialized:data' WHERE option_name = 'sidebars_widgets'"
		);

		$report = $this->runMigrate( dryRun: false );

		self::assertArrayHasKey( 1, $report['corrupt_sites'] );
		self::assertSame( 0, $report['instances_migrated'] );
		self::assertNotEmpty(
			array_filter(
				$report['warnings'],
				static fn ( string $warning ): bool => str_contains( $warning, 'corrupt "sidebars_widgets"' )
			)
		);
		// The destination's own widget data is untouched.
		self::assertSame(
			'Dest widget',
			unserialize(
				(string) $this->destination->fetchScalar(
					"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'widget_text'"
				)
			)[1]['title']
		);
	}

	public function testMissingInstanceReferenceIsCounted(): void {
		$this->seedSiteOneWidgets();
		// sidebar-2 references text-9, which has no instance.
		$optionsTable = $this->source->siteTable( 'options', 1 );
		$sidebars = unserialize(
			(string) $this->source->fetchScalar(
				"SELECT option_value FROM {$optionsTable} WHERE option_name = 'sidebars_widgets'"
			)
		);
		$sidebars['sidebar-2'] = array( 'text-9' );
		$this->source->execute(
			"UPDATE {$optionsTable} SET option_value = :value WHERE option_name = 'sidebars_widgets'",
			array( 'value' => serialize( $sidebars ) )
		);

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 1, $report['instances_missing'] );
		$destSidebars = unserialize(
			(string) $this->destination->fetchScalar(
				"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'sidebars_widgets'"
			)
		);
		// sidebar-2's only reference was missing -> the area is skipped.
		self::assertArrayNotHasKey( 'site1_sidebar-2', $destSidebars );
	}

	public function testDryRunWritesNothing(): void {
		$this->seedSiteOneWidgets();
		$this->seedSiteSevenWidgets();

		$report = $this->runMigrate( dryRun: true );

		self::assertSame( 3, $report['instances_migrated'] );
		self::assertSame( 4, $report['sidebar_areas_written'] );
		$optionsTable = $this->destination->siteTable( 'options', 1 );
		self::assertSame( 2, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$optionsTable}" ) );
	}

	public function testRerunSkipsAlreadyMigratedWidgets(): void {
		$this->seedSiteOneWidgets();
		$this->seedSiteSevenWidgets();
		$this->runMigrate( dryRun: false );

		$second = $this->runMigrate( dryRun: false );

		// 3 widget options + sidebars_widgets for each of 2 sites.
		self::assertSame( 0, $second['instances_migrated'] );
		self::assertSame( 5, $second['instances_skipped'] );
		$text = unserialize(
			(string) $this->destination->fetchScalar(
				"SELECT option_value FROM {$this->destination->siteTable( 'options', 1 )} WHERE option_name = 'widget_text'"
			)
		);
		self::assertSame( 'Dest widget', $text[1]['title'] );
		self::assertSame( 'Hello', $text[2]['title'] );
		self::assertSame( 'Seven text', $text[3]['title'] );
	}

	/**
	 * @return array{
	 *     widget_options_written: int, instances_migrated: int, instances_skipped: int,
	 *     instances_missing: int, sidebar_areas_written: int, targets_unmapped: int,
	 *     corrupt_sites: array<int, string>, warnings: string[],
	 *     by_site: array<int, array{widgets:int, instances:int}>
	 * }
	 */
	private function runMigrate( bool $dryRun ): array {
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );
		foreach ( $this->idMap->entries( 'nav_menu' ) as $key => $value ) {
			[ $siteId, $oldId ] = explode( ':', $key, 2 );
			$idMap->set( 'nav_menu', (int) $siteId, is_numeric( $oldId ) ? (int) $oldId : $oldId, $value );
		}

		return ( new WidgetMigrator() )->migrate(
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
}
