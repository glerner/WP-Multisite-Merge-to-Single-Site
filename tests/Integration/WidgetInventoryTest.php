<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\WidgetInventory;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: WidgetInventory::collect() against a real (SQLite) database —
 * unserializes `sidebars_widgets` + every `widget_%` option row the way
 * the audit and the future MenuMigrator consume them, including the
 * corrupt-value paths.
 *
 * @package MergeMultisite
 */
#[CoversClass( WidgetInventory::class )]
final class WidgetInventoryTest extends SqliteTestCase {

	private Connection $source;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->sqliteConnection( 'wp_', array( 1 ) );
		$optionsTable = $this->source->siteTable( 'options', 1 );

		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'sidebars_widgets',
				'option_value' => serialize(
					array(
						'wp_inactive_widgets' => array( 'text-1' ),
						'sidebar-1' => array( 'text-2', 'custom_html-1' ),
						'array_version' => 3,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_text',
				'option_value' => serialize(
					array(
						1 => array(
					'title' => 'About',
					'text' => 'hello',
					),
						2 => array( 'title' => 'Links' ),
						'_multiwidget' => 1,
					)
				),
			)
		);
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_custom_html',
				'option_value' => serialize(
					array(
					1 => array( 'content' => '<p>x</p>' ),
					'_multiwidget' => 1,
					)
				),
			)
		);
		// A widget option that exists but cannot be unserialized.
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'widget_broken',
				'option_value' => 'a:2:{i:1;o:8:"whatever";', // truncated serialization
			)
		);
		// A non-widget option that must not be picked up.
		WpTestSchema::insertOption(
			$this->source->pdo(),
			$optionsTable,
			array(
				'option_name' => 'blogname',
				'option_value' => 'Example',
			)
		);
	}

	public function testCollectParsesSidebarsAndWidgetOptions(): void {
		$result = ( new WidgetInventory() )->collect( $this->source, 1 );

		self::assertFalse( $result['sidebars_corrupt'] );
		self::assertSame( array( 'text-2', 'custom_html-1' ), $result['sidebars']['sidebar-1'] );
		self::assertSame( array( 'text-1' ), $result['sidebars']['wp_inactive_widgets'] );

		self::assertArrayHasKey( 'widget_text', $result['widget_options'] );
		self::assertSame( 'ok', $result['widget_options']['widget_text']['status'] );
		self::assertSame( 'About', $result['widget_options']['widget_text']['instances'][1]['title'] );

		self::assertArrayHasKey( 'widget_custom_html', $result['widget_options'] );
		self::assertSame( '<p>x</p>', $result['widget_options']['widget_custom_html']['instances'][1]['content'] );
	}

	public function testCollectMarksUnparseableWidgetOptionCorrupt(): void {
		$result = ( new WidgetInventory() )->collect( $this->source, 1 );

		self::assertSame( 'corrupt', $result['widget_options']['widget_broken']['status'] );
		self::assertSame( array(), $result['widget_options']['widget_broken']['instances'] );
	}

	public function testCollectIgnoresNonWidgetOptions(): void {
		$result = ( new WidgetInventory() )->collect( $this->source, 1 );

		self::assertArrayNotHasKey( 'blogname', $result['widget_options'] );
		self::assertCount( 3, $result['widget_options'] );
	}

	public function testCollectReturnsNullSidebarsWhenOptionMissing(): void {
		// Use a fresh site (blog 2) with an options table but no
		// sidebars_widgets row.
		$pdo = $this->newSqlitePdo( 'wp_', array( 2 ) );
		WpTestSchema::insertOption(
			$pdo,
			'wp_2_options',
			array(
			'option_name' => 'blogname',
			'option_value' => 'Two',
			)
		);
		$connection = $this->sqliteConnection( 'wp_', array( 2 ), '/tmp/uploads', null, $pdo );

		$result = ( new WidgetInventory() )->collect( $connection, 2 );

		self::assertNull( $result['sidebars'] );
		self::assertFalse( $result['sidebars_corrupt'] );
		self::assertSame( array(), $result['widget_options'] );
	}
}
