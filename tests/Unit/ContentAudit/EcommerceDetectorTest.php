<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\EcommerceDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( EcommerceDetector::class )]
final class EcommerceDetectorTest extends TestCase {

	public function testDetectsWooCommerceByPostType(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'product', '' );

		self::assertSame( array( 'WooCommerce' ), $detector->detect( $post ) );
	}

	public function testDetectsWooCommerceByShortcode(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'page', '[add_to_cart id="1"]' );

		self::assertSame( array( 'WooCommerce' ), $detector->detect( $post ) );
	}

	public function testDetectsSureCartByPostType(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'sc_product', '' );

		self::assertSame( array( 'SureCart' ), $detector->detect( $post ) );
	}

	public function testDetectsEasyDigitalDownloadsByPostType(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'download', '' );

		self::assertSame( array( 'Easy Digital Downloads' ), $detector->detect( $post ) );
	}

	public function testDetectsEasyDigitalDownloadsByShortcode(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'page', '[purchase_link id="1"]' );

		self::assertSame( array( 'Easy Digital Downloads' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoEcommercePluginFound(): void {
		$detector = new EcommerceDetector();

		$post = $this->makePost( 'page', '<p>Nothing for sale.</p>' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	private function makePost( string $postType, string $content ): ScannedPost {
		return new ScannedPost( blogId: 1, postId: 1, postType: $postType, postStatus: 'publish', slug: 'shop', postTitle: 'Shop', content: $content, meta: array() );
	}
}
