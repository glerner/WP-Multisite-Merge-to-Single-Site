<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\MediaMigrator;
use MergeMultisite\Migration\UploadsPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( MediaMigrator::class )]
final class MediaMigratorTest extends TestCase {

	private function migrator(): MediaMigrator {
		return new MediaMigrator( new UploadsPathResolver( '/tmp/uploads' ), '/tmp/dest-uploads' );
	}

	public function testVariantBasenamesExtractsSizesAndOriginalImage(): void {
		$metadata = serialize(
			array(
				'file'            => '2024/01/photo-scaled.jpg',
				'original_image'  => 'photo.jpg',
				'sizes'           => array(
					'thumbnail' => array(
			'file' => 'photo-scaled-150x150.jpg',
			'width' => 150,
			'height' => 150,
				),
					'large'     => array(
				'file' => 'photo-scaled-1024x683.jpg',
				'width' => 1024,
				'height' => 683,
				),
				),
				'image_meta'      => array( 'aperture' => 0 ),
			)
		);

		self::assertSame(
			array(
				'photo.jpg',
				'photo-scaled-150x150.jpg',
				'photo-scaled-1024x683.jpg',
			),
			$this->migrator()->variantBasenames( $metadata )
		);
	}

	public function testVariantBasenamesReturnsEmptyForUnparseableMetadata(): void {
		self::assertSame( array(), $this->migrator()->variantBasenames( 'not serialized' ) );
		self::assertSame( array(), $this->migrator()->variantBasenames( serialize( 'just a string' ) ) );
	}

	public function testRewriteAttachmentMetadataUpdatesPathsAndKeepsStructure(): void {
		$metadata = serialize(
			array(
				'width'          => 2000,
				'height'         => 1000,
				'file'           => '2024/01/photo-scaled.jpg',
				'original_image' => 'photo.jpg',
				'sizes'          => array(
					'thumbnail' => array(
			'file' => 'photo-scaled-150x150.jpg',
			'width' => 150,
			'height' => 150,
				),
					'large'     => array(
				'file' => 'photo-scaled-1024x683.jpg',
				'width' => 1024,
				'height' => 683,
				),
				),
				'image_meta'     => array(
			'aperture' => 0,
			'keywords' => array( 'a', 'b' ),
			),
			)
		);

		$renamed = array(
			'photo-scaled-150x150.jpg' => 'photo_site7-scaled-150x150.jpg',
			'photo-scaled-1024x683.jpg' => 'photo_site7-scaled-1024x683.jpg',
			'photo.jpg'                => 'photo_site7.jpg',
		);

		$rewritten = $this->migrator()->rewriteAttachmentMetadata( $metadata, '2024/01/photo_site7-scaled.jpg', $renamed );

		// Re-serialize/unserialize round-trip: string-length prefixes stay
		// correct (SerializedDataRewriter recalculates them) and the whole
		// structure survives.
		self::assertNotSame( $metadata, $rewritten );
		$decoded = unserialize( $rewritten, array( 'allowed_classes' => false ) );
		self::assertIsArray( $decoded );

		self::assertSame( '2024/01/photo_site7-scaled.jpg', $decoded['file'] );
		self::assertSame( 'photo_site7.jpg', $decoded['original_image'] );
		self::assertSame( 'photo_site7-scaled-150x150.jpg', $decoded['sizes']['thumbnail']['file'] );
		self::assertSame( 'photo_site7-scaled-1024x683.jpg', $decoded['sizes']['large']['file'] );
		self::assertSame( 150, $decoded['sizes']['thumbnail']['width'] );
		self::assertSame( 0, $decoded['image_meta']['aperture'] );
		self::assertSame( array( 'a', 'b' ), $decoded['image_meta']['keywords'] );
	}

	public function testPlanTargetsRenamesCollidingMainsWithSiteMarker(): void {
		$files = array(
			array(
		'blog_id' => 7,
		'kind' => 'main',
		'relative' => '2024/01/logo.png',
		),
			array(
		'blog_id' => 9,
		'kind' => 'main',
		'relative' => '2024/01/logo.png',
		),
		);

		$targets = $this->migrator()->planTargets( $files, array( 'hash-a', 'hash-b' ) );

		self::assertSame( '2024/01/logo_site7.png', $targets[0]['target'] );
		self::assertSame( '2024/01/logo_site9.png', $targets[1]['target'] );
		self::assertTrue( $targets[0]['renamed'] );
		self::assertTrue( $targets[1]['renamed'] );
	}

	public function testPlanTargetsKeepsSizeSuffixAfterSiteMarkerForVariants(): void {
		$files = array(
			array(
		'blog_id' => 7,
		'kind' => 'variant',
		'relative' => '2024/01/logo-150x150.png',
		),
			array(
		'blog_id' => 9,
		'kind' => 'variant',
		'relative' => '2024/01/logo-150x150.png',
		),
		);

		$targets = $this->migrator()->planTargets( $files, array( 'hash-a', 'hash-b' ) );

		// WordPress's -{W}x{H} suffix must stay LAST so size filenames
		// still line up with the renamed base (PLAN.md §7.2).
		self::assertSame( '2024/01/logo_site7-150x150.png', $targets[0]['target'] );
		self::assertSame( '2024/01/logo_site9-150x150.png', $targets[1]['target'] );
	}

	public function testPlanTargetsDedupsIdenticalFilesToSameTarget(): void {
		$files = array(
			array(
		'blog_id' => 7,
		'kind' => 'main',
		'relative' => '2024/01/logo.png',
		),
			array(
		'blog_id' => 9,
		'kind' => 'main',
		'relative' => '2024/01/logo.png',
		),
		);

		$targets = $this->migrator()->planTargets( $files, array( 'same-hash', 'same-hash' ) );

		// Identical content: one physical file, no rename.
		self::assertSame( '2024/01/logo.png', $targets[0]['target'] );
		self::assertSame( '2024/01/logo.png', $targets[1]['target'] );
		self::assertFalse( $targets[0]['renamed'] );
		self::assertFalse( $targets[1]['renamed'] );
	}

	public function testCollisionAlternativesNumberAfterSiteMarker(): void {
		$mains = MediaMigrator::collisionAlternatives( '2024/01/logo.png', 'main', 7 );
		self::assertSame( '2024/01/logo_site7.png', $mains[0] );
		self::assertSame( '2024/01/logo_site7-2.png', $mains[1] );
		self::assertCount( 9, $mains );

		$variants = MediaMigrator::collisionAlternatives( '2024/01/logo-150x150.png', 'variant', 7 );
		self::assertSame( '2024/01/logo_site7-150x150.png', $variants[0] );
		self::assertSame( '2024/01/logo_site7-2-150x150.png', $variants[1] );
	}

	public function testCollisionAlternativesDoNotDoubleSuffixAlreadyRenamedNames(): void {
		$alternatives = MediaMigrator::collisionAlternatives( '2024/01/logo_site9.png', 'main', 7 );
		self::assertSame( '2024/01/logo_site9.png', $alternatives[0] );
		self::assertSame( '2024/01/logo_site9-2.png', $alternatives[1] );
	}
}
