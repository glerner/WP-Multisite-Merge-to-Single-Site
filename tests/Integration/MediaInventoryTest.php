<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\MediaInventory;
use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\UploadsPathResolver;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: MediaInventory::collect()/fingerprints() against a real
 * (SQLite) database and a real temp uploads tree, so the JOIN, the
 * per-layout path resolution, and the found/missing split are all
 * exercised as the audit/migration pipeline uses them.
 *
 * @package MergeMultisite
 */
#[CoversClass( MediaInventory::class )]
final class MediaInventoryTest extends SqliteTestCase {

	private string $uploadsDir;

	private Connection $source;

	protected function setUp(): void {
		parent::setUp();

		$this->uploadsDir = sys_get_temp_dir() . '/merge-multisite-media-inventory-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->uploadsDir . '/sites/2/2024/01', 0777, true );
		mkdir( $this->uploadsDir . '/2023/12', 0777, true );
		file_put_contents( $this->uploadsDir . '/sites/2/2024/01/photo.jpg', 'site-2-photo' );
		file_put_contents( $this->uploadsDir . '/2023/12/logo.png', 'main-logo' );

		$this->source = $this->sqliteConnection( 'wp_', array( 1, 2 ), $this->uploadsDir );

		// Blog 1 (main site): logo.png at the bare uploads root.
		$postId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 1 ),
			array(
			'post_type' => 'attachment',
			'post_title' => 'Logo',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 1 ),
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attached_file',
			'meta_value' => '2023/12/logo.png',
			)
		);

		// Blog 2: photo.jpg under sites/2/ (modern layout).
		$postId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 2 ),
			array(
			'post_type' => 'attachment',
			'post_title' => 'Photo',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 2 ),
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attached_file',
			'meta_value' => '2024/01/photo.jpg',
			)
		);

		// Blog 2: a second attachment whose file does not exist on disk.
		$postId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 2 ),
			array(
			'post_type' => 'attachment',
			'post_title' => 'Missing',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 2 ),
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attached_file',
			'meta_value' => '2024/01/gone.jpg',
			)
		);
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->uploadsDir ?? '' );
		parent::tearDown();
	}

	public function testCollectSplitsFoundAndMissingAcrossLayouts(): void {
		$inventory = new MediaInventory( new UploadsPathResolver( $this->uploadsDir ) );
		$sites = array(
			new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ),
			new Site( blogId: 2, domain: 'two.example.test', path: '/', title: 'Two', deleted: false, included: true ),
		);

		$result = $inventory->collect( $this->source, $sites );

		self::assertCount( 2, $result['found'] );
		self::assertCount( 1, $result['missing'] );

		$byRelative = array();
		foreach ( $result['found'] as $file ) {
			$byRelative[ $file['relative'] ] = $file;
		}

		self::assertSame( $this->uploadsDir . '/2023/12/logo.png', $byRelative['2023/12/logo.png']['path'] );
		self::assertSame( 1, $byRelative['2023/12/logo.png']['blog_id'] );

		self::assertSame( $this->uploadsDir . '/sites/2/2024/01/photo.jpg', $byRelative['2024/01/photo.jpg']['path'] );
		self::assertSame( 2, $byRelative['2024/01/photo.jpg']['blog_id'] );

		self::assertSame( '2024/01/gone.jpg', $result['missing'][0]['relative'] );
		self::assertSame( 2, $result['missing'][0]['blog_id'] );
	}

	public function testCollectIgnoresNonAttachmentPosts(): void {
		// A plain post carrying _wp_attached_file meta must not be collected.
		$postId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', 2 ),
			array(
			'post_type' => 'post',
			'post_title' => 'Not an attachment',
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$this->source->siteTable( 'postmeta', 2 ),
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attached_file',
			'meta_value' => '2024/01/photo.jpg',
			)
		);

		$inventory = new MediaInventory( new UploadsPathResolver( $this->uploadsDir ) );
		$sites = array( new Site( blogId: 2, domain: 'two.example.test', path: '/', title: 'Two', deleted: false, included: true ) );

		$result = $inventory->collect( $this->source, $sites );

		self::assertCount( 1, $result['found'] );
		self::assertCount( 1, $result['missing'] );
	}

	public function testFingerprintsAreIndexAlignedWithFoundFiles(): void {
		$inventory = new MediaInventory( new UploadsPathResolver( $this->uploadsDir ) );
		$sites = array(
			new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ),
			new Site( blogId: 2, domain: 'two.example.test', path: '/', title: 'Two', deleted: false, included: true ),
		);

		$found = $inventory->collect( $this->source, $sites )['found'];
		$fingerprints = $inventory->fingerprints( $found );

		self::assertCount( 2, $fingerprints );
		// Deterministic content-based keys, different per file.
		self::assertNotSame( $fingerprints[0], $fingerprints[1] );
		self::assertSame( $fingerprints[0], $inventory->fingerprints( array( $found[0] ) )[0] );
	}
}
