<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\BlockDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( BlockDetector::class )]
final class BlockDetectorTest extends TestCase {

	public function testDetectsNonCoreBlocksAndIgnoresCoreByDefault(): void {
		$detector = new BlockDetector();

		$post = $this->makePost(
			'<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'
			. '<!-- wp:sureforms/form-selector {"id":1} /-->'
		);

		self::assertSame( array( 'sureforms/form-selector' ), $detector->detect( $post ) );
	}

	public function testCanIncludeCoreBlocksWhenConfigured(): void {
		$detector = new BlockDetector( includeCoreBlocks: true );

		$post = $this->makePost( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' );

		self::assertSame( array( 'core/paragraph' ), $detector->detect( $post ) );
	}

	public function testLegacyCoreEmbedNamespaceIsTreatedAsCore(): void {
		$detector = new BlockDetector();

		// Old posts serialize the core Embed block as "core-embed/*".
		$post = $this->makePost(
			'<!-- wp:core-embed/youtube {"url":"https://youtu.be/x"} /-->'
			. '<!-- wp:uagb/container /-->'
		);

		self::assertSame( array( 'uagb/container' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoBlocksFound(): void {
		$detector = new BlockDetector();

		$post = $this->makePost( '[shortcode_only]' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost( 1, 1, 'post', 'publish', 'my-post', 'My Post', $content, array() );
	}
}
