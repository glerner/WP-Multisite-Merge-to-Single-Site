<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\ContentAudit;

use MergeMultisite\ContentAudit\Detectors\GalleryDetector;
use MergeMultisite\ContentAudit\ScannedPost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( GalleryDetector::class )]
final class GalleryDetectorTest extends TestCase {

	#[DataProvider( 'signatureProvider' )]
	public function testDetectsKnownGallerySignatures( string $content, string $expectedLabel ): void {
		$detector = new GalleryDetector();

		$post = $this->makePost( $content );

		self::assertSame( array( $expectedLabel ), $detector->detect( $post ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function signatureProvider(): array {
		return array(
			'Core Gallery shortcode' => array( '[gallery ids="1,2,3"]', 'Core Gallery' ),
			'Core Gallery block'     => array( '<!-- wp:gallery -->', 'Core Gallery' ),
			'Envira Gallery'         => array( '[envira-gallery id="1"]', 'Envira Gallery' ),
			'NextGEN Gallery'        => array( '[nggallery id="1"]', 'NextGEN Gallery' ),
			'Divi Gallery'           => array( '[et_pb_gallery][/et_pb_gallery]', 'Divi Gallery' ),
			'Kadence Gallery'        => array( '<!-- wp:kadence/advancedgallery /-->', 'Kadence Gallery' ),
		);
	}

	public function testDetectsElementorGalleryViaElementorDataMeta(): void {
		$detector = new GalleryDetector();

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'portfolio',
			postTitle: 'Portfolio',
			content: '',
			meta: array(
				'_elementor_data' => array( '[{"widgetType":"gallery","settings":{}}]' ),
			)
		);

		self::assertSame( array( 'Elementor Gallery' ), $detector->detect( $post ) );
	}

	public function testDetectsBeaverBuilderGalleryViaFlBuilderDataMeta(): void {
		$detector = new GalleryDetector();

		// _fl_builder_data is PHP-serialized, not JSON: a tree of node
		// objects where module nodes carry settings->type.
		$layout = array(
			(object) array( 'type' => 'row' ),
			(object) array(
				'type'     => 'module',
				'settings' => (object) array( 'type' => 'gallery' ),
			),
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'portfolio',
			postTitle: 'Portfolio',
			content: '',
			meta: array(
				'_fl_builder_data' => array( serialize( $layout ) ),
			)
		);

		self::assertSame( array( 'Beaver Builder Gallery' ), $detector->detect( $post ) );
	}

	public function testBeaverBuilderSerializedDataWithoutGalleryIsNotReported(): void {
		$detector = new GalleryDetector();

		$layout = array(
			(object) array(
				'type'     => 'module',
				'settings' => (object) array( 'type' => 'heading' ),
			),
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'portfolio',
			postTitle: 'Portfolio',
			content: '',
			meta: array(
				'_fl_builder_data' => array( serialize( $layout ) ),
			)
		);

		self::assertSame( array(), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoGalleryFound(): void {
		$detector = new GalleryDetector();

		$post = $this->makePost( '<p>No galleries here.</p>' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'content_signatures' teaches the detector a
	 * gallery plugin it doesn't know, without a source edit.
	 */
	public function testExtrasContentSignatureAddsNewPlugin(): void {
		$detector = new GalleryDetector(
			array( 'content_signatures' => array( 'My Gallery' => array( '\[mygallery\b' ) ) )
		);

		$post = $this->makePost( '[mygallery ids="1,2"]' );

		self::assertSame( array( 'My Gallery' ), $detector->detect( $post ) );
	}

	/**
	 * Detector_extras 'serialized_meta_signatures' names a
	 * PHP-serialized builder layout's gallery module, same as the
	 * built-in Beaver Builder signature.
	 */
	public function testExtrasSerializedMetaSignatureDetectsBuilderModule(): void {
		$detector = new GalleryDetector(
			array( 'serialized_meta_signatures' => array( 'My Builder Gallery' => array( '_my_builder_data', 'photos' ) ) )
		);

		$layout = array(
			(object) array(
				'type'     => 'module',
				'settings' => (object) array( 'type' => 'photos' ),
			),
		);

		$post = new ScannedPost(
			blogId: 1,
			postId: 1,
			postType: 'page',
			postStatus: 'publish',
			slug: 'portfolio',
			postTitle: 'Portfolio',
			content: '',
			meta: array(
				'_my_builder_data' => array( serialize( $layout ) ),
			)
		);

		self::assertSame( array( 'My Builder Gallery' ), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost( blogId: 1, postId: 1, postType: 'page', postStatus: 'publish', slug: 'portfolio', postTitle: 'Portfolio', content: $content, meta: array() );
	}
}
