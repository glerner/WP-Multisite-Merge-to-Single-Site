<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\NeedsReviewDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( NeedsReviewDetector::class )]
final class NeedsReviewDetectorTest extends TestCase {

	public function testFlagsNonCoreBlockNamespaces(): void {
		$detector = new NeedsReviewDetector();

		// A page built with block-library plugins breaks without them.
		$post = $this->makePost(
			'<!-- wp:uagb/container /--><!-- wp:greenshift-blocks/heading /-->'
			. '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->'
			. '<!-- wp:core-embed/youtube /-->',
			array()
		);

		self::assertSame( array( 'Non-core blocks: greenshift-blocks, uagb' ), $detector->detect( $post ) );
	}

	public function testPureCoreContentIsNotFlagged(): void {
		$detector = new NeedsReviewDetector();

		$post = $this->makePost(
			'<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph --><!-- wp:core-embed/youtube /-->',
			array()
		);

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testDiviBuilderFlaggedFromMeta(): void {
		$detector = new NeedsReviewDetector();

		$post = $this->makePost( 'content', array( '_et_pb_use_builder' => array( 'on' ) ) );

		self::assertContains( 'Divi', $detector->detect( $post ) );
	}

	/**
	 * @param array<string, string[]> $meta
	 */
	private function makePost( string $content, array $meta ): ScannedPost {
		return new ScannedPost( 1, 1, 'page', 'publish', 'p', 'P', $content, $meta );
	}
}
