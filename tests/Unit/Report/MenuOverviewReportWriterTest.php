<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Report;

use MergeMultisite\Report\MenuOverviewReportWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * MenuOverviewReportWriter pure logic: tree building (roots by
 * parent, siblings by menu_order), the Markdown tree rendering, and
 * the JSON passthrough.
 *
 * @package MergeMultisite
 */
#[CoversClass( MenuOverviewReportWriter::class )]
final class MenuOverviewReportWriterTest extends TestCase {

	private function item( int $id, string $label, int $parent = 0, int $menuOrder = 1 ): array {
		return array(
			'id' => $id,
			'label' => $label,
			'type' => 'custom',
			'object' => 'custom',
			'target' => 'https://example.test/' . $id,
			'parent' => $parent,
			'menu_order' => $menuOrder,
		);
	}

	public function testTreeBuildsRootsAndNestingSortedByMenuOrder(): void {
		$writer = new MenuOverviewReportWriter();

		$tree = $writer->tree(
			array(
				$this->item( 3, 'Third', parent: 1, menuOrder: 2 ),
				$this->item( 1, 'Root one', menuOrder: 1 ),
				$this->item( 4, 'Nested', parent: 3, menuOrder: 1 ),
				$this->item( 2, 'Root two', menuOrder: 2 ),
				$this->item( 5, 'Lost', parent: 99, menuOrder: 9 ), // parent not an item -> root.
			)
		);

		self::assertCount( 3, $tree ); // roots: 1, 2, 5.
		self::assertSame( 1, (int) $tree[0]['item']['id'] );
		self::assertSame( 2, (int) $tree[1]['item']['id'] );
		self::assertSame( 5, (int) $tree[2]['item']['id'] );

		self::assertCount( 1, $tree[0]['children'] );
		self::assertSame( 3, (int) $tree[0]['children'][0]['item']['id'] );
		self::assertCount( 1, $tree[0]['children'][0]['children'] );
		self::assertSame( 4, (int) $tree[0]['children'][0]['children'][0]['item']['id'] );
	}

	public function testMarkdownRendersSiteMenuAndOrphanedSections(): void {
		$writer = new MenuOverviewReportWriter();

		$markdown = $writer->toMarkdown(
			array(
				1 => array(
					'domain' => 'main.example.test',
					'menus' => array(
						array(
							'name' => 'Main Menu',
							'items' => array(
								$this->item( 1, 'About', menuOrder: 1 ),
								$this->item( 2, 'Child', parent: 1, menuOrder: 1 ),
							),
						),
					),
					'orphaned_items' => array(
						$this->item( 9, 'Loose link', menuOrder: 1 ),
					),
				),
			)
		);

		self::assertStringContainsString( '## Site 1 — main.example.test', $markdown );
		self::assertStringContainsString( '### Menu: Main Menu', $markdown );
		self::assertStringContainsString( '- About (custom → https://example.test/1)', $markdown );
		self::assertStringContainsString( '  - Child (custom → https://example.test/2)', $markdown );
		self::assertStringContainsString( '### Orphaned items (not assigned to any menu)', $markdown );
		self::assertStringContainsString( '- Loose link (custom → https://example.test/9)', $markdown );
	}

	public function testMarkdownHandlesEmptyMenusAndUntitledItems(): void {
		$writer = new MenuOverviewReportWriter();

		$markdown = $writer->toMarkdown(
			array(
				7 => array(
					'domain' => 'seven.example.test',
					'menus' => array(
						array(
			'name' => 'Empty Menu',
			'items' => array(),
					),
					),
					'orphaned_items' => array( $this->item( 1, '', menuOrder: 1 ) ),
				),
			)
		);

		self::assertStringContainsString( '_Empty menu._', $markdown );
		self::assertStringContainsString( '- (untitled) (custom → https://example.test/1)', $markdown );
	}

	public function testJsonPassthrough(): void {
		$writer = new MenuOverviewReportWriter();

		$overview = array(
			1 => array(
				'domain' => 'main.example.test',
				'menus' => array(),
				'orphaned_items' => array(),
			),
		);

		$json = $writer->toJson( $overview );

		self::assertSame( $overview, json_decode( $json, true ) );
	}
}
