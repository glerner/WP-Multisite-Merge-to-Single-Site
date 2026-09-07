<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\ShortcodeDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ShortcodeDetector::class )]
final class ShortcodeDetectorTest extends TestCase {

	public function testDetectsShortcodeTagName(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '[gallery ids="1,2,3"]' );

		self::assertSame( array( 'gallery' ), $detector->detect( $post ) );
	}

	public function testDetectsMultipleDistinctShortcodesSortedAlphabetically(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '[wpforms id="1"] [gallery] [wpforms id="2"]' );

		self::assertSame( array( 'gallery', 'wpforms' ), $detector->detect( $post ) );
	}

	public function testExcludesClosingTags(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '[gallery]content[/gallery]' );

		self::assertSame( array( 'gallery' ), $detector->detect( $post ) );
	}

	public function testDetectsSelfClosingShortcode(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '[sc_product/]' );

		self::assertSame( array( 'sc_product' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoShortcodesFound(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost( 1, 1, 'post', 'publish', 'my-post', $content, array() );
	}
}
