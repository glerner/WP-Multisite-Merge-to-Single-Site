<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\WidgetInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( WidgetInventory::class )]
final class WidgetInventoryTest extends TestCase {

	public function testParseWidgetIdSplitsTypeAndIndex(): void {
		self::assertSame(
			array(
			'type' => 'text',
			'index' => 3,
			),
			WidgetInventory::parseWidgetId( 'text-3' )
		);
		self::assertSame(
			array(
			'type' => 'custom_html',
			'index' => 12,
			),
			WidgetInventory::parseWidgetId( 'custom_html-12' )
		);
	}

	public function testParseWidgetIdRejectsNonWidgetIds(): void {
		self::assertNull( WidgetInventory::parseWidgetId( 'wp_inactive_widgets' ) );
		self::assertNull( WidgetInventory::parseWidgetId( 'array_version' ) );
		self::assertNull( WidgetInventory::parseWidgetId( 'no-index-suffix' ) );
		self::assertNull( WidgetInventory::parseWidgetId( '' ) );
	}
}
