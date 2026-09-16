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

	public function testIgnoresShortcodeLikeTextInsidePreAndCodeBlocks(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost(
			'<p>Text [gallery]</p>'
			. '<pre>RewriteRule ^foo$ - [NC,L]\narray ( [file] => "x" [function] => "y" )</pre>'
			. '<code>[some_example]</code>'
		);

		self::assertSame( array( 'gallery' ), $detector->detect( $post ) );
	}

	public function testIgnoresArrayDumpKeys(): void {
		$detector = new ShortcodeDetector();

		// print_r()/var_dump() output pasted without a <pre> wrapper.
		$post = $this->makePost( 'Dump: [file] => "x" [last_error] => "" [blog_id] => 20 [gallery]' );

		self::assertSame( array( 'gallery' ), $detector->detect( $post ) );
	}

	public function testIgnoresTagsStartingWithUppercase(): void {
		$detector = new ShortcodeDetector();

		// Rewrite flags and capitalized prose are not WP shortcodes,
		// whose tag names always start lowercase.
		$post = $this->makePost( 'RewriteRule x - [NC] [L] See [This] note [et_pb_row]' );

		self::assertSame( array( 'et_pb_row' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoShortcodesFound(): void {
		$detector = new ShortcodeDetector();

		$post = $this->makePost( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testConfiguredIgnoreListSuppressesNoiseWords(): void {
		// config/shortcode-ignore.php entries may be written with or
		// without square brackets; matching is case-insensitive.
		$detector = new ShortcodeDetector( array( 'amended', '[retweet]', 'Facebook' ) );

		$post = $this->makePost( 'Text [amended] [retweet] [facebook] [gallery]' );

		self::assertSame( array( 'gallery' ), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost( 1, 1, 'post', 'publish', 'my-post', 'My Post', $content, array() );
	}
}
