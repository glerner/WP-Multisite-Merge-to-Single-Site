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
			1,
			1,
			'page',
			'publish',
			'portfolio',
			'',
			array( '_elementor_data' => array( '[{"widgetType":"gallery","settings":{}}]' ) )
		);

		self::assertSame( array( 'Elementor Gallery' ), $detector->detect( $post ) );
	}

	public function testDetectsBeaverBuilderGalleryViaFlBuilderDataMeta(): void {
		$detector = new GalleryDetector();

		$post = new ScannedPost(
			1,
			1,
			'page',
			'publish',
			'portfolio',
			'',
			array( '_fl_builder_data' => array( '[{"type":"gallery"}]' ) )
		);

		self::assertSame( array( 'Beaver Builder Gallery' ), $detector->detect( $post ) );
	}

	public function testReturnsEmptyArrayWhenNoGalleryFound(): void {
		$detector = new GalleryDetector();

		$post = $this->makePost( '<p>No galleries here.</p>' );

		self::assertSame( array(), $detector->detect( $post ) );
	}

	private function makePost( string $content ): ScannedPost {
		return new ScannedPost( 1, 1, 'page', 'publish', 'portfolio', $content, array() );
	}
}
