<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\NavMenuItemDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( NavMenuItemDetector::class )]
final class NavMenuItemDetectorTest extends TestCase {

	public function testIgnoresNonMenuPostTypes(): void {
		$detector = new NavMenuItemDetector();

		$post = new ScannedPost( 1, 1, 'page', 'publish', 'p', 'P', '', array( '_menu_item_type' => array( 'custom' ) ) );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testCustomLinkShowsUrl(): void {
		$detector = new NavMenuItemDetector();

		$post = $this->menuItem(
			array(
				'_menu_item_type' => array( 'custom' ),
				'_menu_item_url'  => array( 'https://example.com/page/' ),
			)
		);

		self::assertSame( array( 'custom: https://example.com/page/' ), $detector->detect( $post ) );
	}

	public function testPostTypeLinkShowsObjectAndId(): void {
		$detector = new NavMenuItemDetector();

		$post = $this->menuItem(
			array(
				'_menu_item_type'      => array( 'post_type' ),
				'_menu_item_object'    => array( 'page' ),
				'_menu_item_object_id' => array( '123' ),
			)
		);

		self::assertSame( array( 'page #123' ), $detector->detect( $post ) );
	}

	public function testTaxonomyAndArchiveLinks(): void {
		$detector = new NavMenuItemDetector();

		$taxonomy = $this->menuItem(
			array(
				'_menu_item_type'      => array( 'taxonomy' ),
				'_menu_item_object'    => array( 'category' ),
				'_menu_item_object_id' => array( '45' ),
			)
		);
		$archive  = $this->menuItem(
			array(
				'_menu_item_type'   => array( 'post_type_archive' ),
				'_menu_item_object' => array( 'product' ),
			)
		);

		self::assertSame( array( 'category #45' ), $detector->detect( $taxonomy ) );
		self::assertSame( array( 'archive: product' ), $detector->detect( $archive ) );
	}

	public function testMissingTypeIsReportedAsUnknown(): void {
		$detector = new NavMenuItemDetector();

		self::assertSame( array( 'unknown menu item type' ), $detector->detect( $this->menuItem( array() ) ) );
	}

	/**
	 * @param array<string, string[]> $meta
	 */
	private function menuItem( array $meta ): ScannedPost {
		return new ScannedPost( 1, 1, 'nav_menu_item', 'publish', 'item', 'Item', '', $meta );
	}
}
