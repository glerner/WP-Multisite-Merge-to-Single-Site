<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\PageBuilderDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PageBuilderDetector::class )]
final class PageBuilderDetectorTest extends TestCase {

	public function testDetectsElementorViaMeta(): void {
		$detector = new PageBuilderDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'home', postTitle: 'Home', content: '', meta: array( '_elementor_data' => array( '[]' ) ) );

		self::assertSame( array( 'Elementor' ), $detector->detect( $post ) );
	}

	public function testDetectsDiviViaMetaFlag(): void {
		$detector = new PageBuilderDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'home', postTitle: 'Home', content: '', meta: array( '_et_pb_use_builder' => array( 'on' ) ) );

		self::assertSame( array( 'Divi' ), $detector->detect( $post ) );
	}

	public function testDetectsDiviViaShortcodeWhenMetaIsMissing(): void {
		$detector = new PageBuilderDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'home', postTitle: 'Home', content: '[et_pb_section][/et_pb_section]', meta: array() );

		self::assertSame( array( 'Divi' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayForPlainGutenbergContent(): void {
		$detector = new PageBuilderDetector();

		$post = new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'home', postTitle: 'Home', content: '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', meta: array() );

		self::assertSame( array(), $detector->detect( $post ) );
	}
}
