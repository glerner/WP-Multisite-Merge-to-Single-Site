<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MediaMigrator;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\UploadsPathResolver;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: MediaMigrator's execution paths (the Phase 4 DB + filesystem
 * side that pure unit tests cannot reach) run end-to-end against
 * SQLite fixtures + real temp directories: cross-site collision
 * renames with their thumbnail variants, hash dedup to one physical
 * file, dry-run writing nothing, and resume idempotency via the
 * migration-map table.
 *
 * @package MergeMultisite
 */
#[CoversClass( MediaMigrator::class )]
final class MediaMigratorEndToEndTest extends SqliteTestCase {

	private string $sourceUploads;

	private string $destUploads;

	private Connection $source;

	private Connection $destination;

	private MigrationTable $mapTable;

	protected function setUp(): void {
		parent::setUp();

		$this->sourceUploads = sys_get_temp_dir() . '/merge-multisite-media-src-' . bin2hex( random_bytes( 4 ) );
		$this->destUploads = sys_get_temp_dir() . '/merge-multisite-media-dst-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sourceUploads . '/2024/01', 0777, true );
		mkdir( $this->sourceUploads . '/sites/7/2024/01', 0777, true );

		$this->source = $this->sqliteConnection( 'wp_', array( 1, 7 ), $this->sourceUploads );
		$this->destination = $this->sqliteConnection( 'wp_', array( 1 ), $this->destUploads );
		$this->mapTable = new MigrationTable();
		$this->mapTable->ensure( $this->destination );
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->sourceUploads ?? '' );
		$this->removeDirectory( $this->destUploads ?? '' );
		parent::tearDown();
	}

	public function testCollidingFilesRenameWithSiteMarkerAcrossSites(): void {
		$this->seedAttachment(
			blogId: 1,
			title: 'Logo Main',
			relative: '2024/01/logo.png',
			content: 'main-logo-bytes',
			metadata: array(
				'file' => '2024/01/logo.png',
				'sizes' => array(
			'thumbnail' => array(
			'file' => 'logo-150x150.png',
			'width' => 150,
			'height' => 150,
				),
			),
			)
		);
		$this->seedAttachment(
			blogId: 7,
			title: 'Logo Seven',
			relative: '2024/01/logo.png',
			content: 'site7-logo-bytes',
			metadata: array(
				'file' => '2024/01/logo.png',
				'sizes' => array(
			'thumbnail' => array(
			'file' => 'logo-150x150.png',
			'width' => 150,
			'height' => 150,
				),
			),
			)
		);

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 2, $report['attachments_migrated'] );
		self::assertSame( 4, $report['files_copied'] );
		self::assertSame( 4, $report['files_renamed'] );
		self::assertSame( 0, $report['files_reused'] );

		// Both sites' files and their thumbnail variants landed under the
		// site-marker names, size suffix last.
		$destFiles = array_values( array_diff( scandir( $this->destUploads . '/2024/01' ), array( '.', '..' ) ) );
		sort( $destFiles );
		self::assertSame(
			array( 'logo_site1-150x150.png', 'logo_site1.png', 'logo_site7-150x150.png', 'logo_site7.png' ),
			$destFiles
		);

		// Each destination attachment's meta points at its renamed file.
		$byTitle = $this->destinationAttachmentTitles();
		self::assertSame( '2024/01/logo_site1.png', $this->attachedFileFor( $byTitle['Logo Main'] ) );
		self::assertSame( '2024/01/logo_site7.png', $this->attachedFileFor( $byTitle['Logo Seven'] ) );

		$metadata1 = unserialize( $this->metaValueFor( $byTitle['Logo Main'], '_wp_attachment_metadata' ), array( 'allowed_classes' => false ) );
		self::assertSame( '2024/01/logo_site1.png', $metadata1['file'] );
		self::assertSame( 'logo_site1-150x150.png', $metadata1['sizes']['thumbnail']['file'] );
	}

	public function testIdenticalFilesDedupToOnePhysicalFile(): void {
		$this->seedAttachment( blogId: 1, title: 'Shared One', relative: '2024/01/shared.png', content: 'same-bytes', metadata: array( 'file' => '2024/01/shared.png' ) );
		$this->seedAttachment( blogId: 7, title: 'Shared Seven', relative: '2024/01/shared.png', content: 'same-bytes', metadata: array( 'file' => '2024/01/shared.png' ) );

		$report = $this->runMigrate( dryRun: false );

		self::assertSame( 2, $report['attachments_migrated'] );
		self::assertSame( 1, $report['files_copied'] );
		self::assertSame( 1, $report['files_reused'] );
		self::assertSame( 0, $report['files_renamed'] );

		// One physical file; both attachment posts reference it.
		self::assertFileExists( $this->destUploads . '/2024/01/shared.png' );
		$destFiles = glob( $this->destUploads . '/2024/01/*' );
		self::assertCount( 1, $destFiles === false ? array() : $destFiles );

		$byTitle = $this->destinationAttachmentTitles();
		self::assertSame( '2024/01/shared.png', $this->attachedFileFor( $byTitle['Shared One'] ) );
		self::assertSame( '2024/01/shared.png', $this->attachedFileFor( $byTitle['Shared Seven'] ) );
	}

	public function testDryRunWritesNothing(): void {
		$this->seedAttachment( blogId: 1, title: 'Logo Main', relative: '2024/01/logo.png', content: 'main-logo-bytes', metadata: array( 'file' => '2024/01/logo.png' ) );

		$report = $this->runMigrate( dryRun: true );

		self::assertSame( 1, $report['attachments_migrated'] );
		self::assertSame( 0, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
		self::assertDirectoryDoesNotExist( $this->destUploads . '/2024' );
	}

	public function testRerunSkipsAlreadyMigratedAttachments(): void {
		$this->seedAttachment( blogId: 1, title: 'Logo Main', relative: '2024/01/logo.png', content: 'main-logo-bytes', metadata: array( 'file' => '2024/01/logo.png' ) );
		$this->runMigrate( dryRun: false );

		$second = $this->runMigrate( dryRun: false );

		self::assertSame( 1, $second['attachments_skipped'] );
		self::assertSame( 0, $second['attachments_migrated'] );
		self::assertSame( 1, $this->destinationRowCount( $this->destination->siteTable( 'posts', 1 ) ) );
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function seedAttachment( int $blogId, string $title, string $relative, string $content, array $metadata ): void {
		$dir = $blogId <= 1
			? dirname( $this->sourceUploads . '/' . $relative )
			: dirname( $this->sourceUploads . '/sites/' . $blogId . '/' . $relative );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $this->sourceFile( $blogId, $relative ), $content );
		foreach ( $metadata['sizes'] ?? array() as $size ) {
			file_put_contents(
				$this->sourceFile( $blogId, dirname( $relative ) . '/' . $size['file'] ),
				'variant-' . $size['file'] . '-' . $blogId
			);
		}

		$postId = WpTestSchema::insertPost(
			$this->source->pdo(),
			$this->source->siteTable( 'posts', $blogId ),
			array(
				'post_type' => 'attachment',
				'post_title' => $title,
			)
		);
		$postMetaTable = $this->source->siteTable( 'postmeta', $blogId );
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attached_file',
			'meta_value' => $relative,
			)
		);
		WpTestSchema::insertPostMeta(
			$this->source->pdo(),
			$postMetaTable,
			array(
			'post_id' => $postId,
			'meta_key' => '_wp_attachment_metadata',
			'meta_value' => serialize( $metadata ),
			)
		);
	}

	private function sourceFile( int $blogId, string $relative ): string {
		return $blogId <= 1
			? $this->sourceUploads . '/' . ltrim( $relative, '/' )
			: $this->sourceUploads . '/sites/' . $blogId . '/' . ltrim( $relative, '/' );
	}

	/**
	 * @return array{attachments_migrated: int, attachments_skipped: int, attachments_failed: int, files_copied: int, files_reused: int, files_renamed: int, files_missing: int, warnings: string[], by_site: array<int, array{attachments: int}>}
	 */
	private function runMigrate( bool $dryRun ): array {
		$migrator = new MediaMigrator( new UploadsPathResolver( $this->sourceUploads ), $this->destUploads );

		// Exactly like bin/migrate.php: prewarm the IdMap from the
		// migration-map table so re-runs skip already-migrated rows.
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );

		return $migrator->migrate(
			$this->source,
			$this->destination,
			$this->testConfig(),
			array(
				new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ),
				new Site( blogId: 7, domain: 'seven.example.test', path: '/', title: 'Seven', deleted: false, included: true ),
			),
			$idMap,
			$this->mapTable,
			$dryRun,
			new Logger( 'error' )
		);
	}

	/**
	 * @return array<string, int> post_title => destination post ID.
	 */
	private function destinationAttachmentTitles(): array {
		$rows = $this->destination->fetchAll(
			"SELECT ID, post_title FROM {$this->destination->siteTable( 'posts', 1 )} WHERE post_type = 'attachment'"
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['post_title'] ] = (int) $row['ID'];
		}

		return $map;
	}

	private function attachedFileFor( int $postId ): string {
		return (string) $this->destination->fetchScalar(
			"SELECT meta_value FROM {$this->destination->siteTable( 'postmeta', 1 )} WHERE post_id = :id AND meta_key = '_wp_attached_file'",
			array( 'id' => $postId )
		);
	}

	private function metaValueFor( int $postId, string $key ): string {
		return (string) $this->destination->fetchScalar(
			"SELECT meta_value FROM {$this->destination->siteTable( 'postmeta', 1 )} WHERE post_id = :id AND meta_key = :key",
			array(
			'id' => $postId,
			'key' => $key,
			)
		);
	}

	private function destinationRowCount( string $table ): int {
		return (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$table}" );
	}
}
