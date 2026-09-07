<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\UploadsPathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( UploadsPathResolver::class )]
final class UploadsPathResolverTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/merge-multisite-uploads-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir . '/uploads/sites/7/2024/01', 0775, true );
		mkdir( $this->tempDir . '/uploads/2024/02', 0775, true );
		mkdir( $this->tempDir . '/blogs.dir/9/files/2020/03', 0775, true );

		file_put_contents( $this->tempDir . '/uploads/sites/7/2024/01/logo.png', 'x' );
		file_put_contents( $this->tempDir . '/uploads/2024/02/main-site.png', 'x' );
		file_put_contents( $this->tempDir . '/blogs.dir/9/files/2020/03/legacy.png', 'x' );
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->tempDir );
	}

	public function testResolvesModernPerSiteLayout(): void {
		$resolver = new UploadsPathResolver( $this->tempDir . '/uploads' );

		$resolved = $resolver->resolve( 7, '2024/01/logo.png' );

		self::assertSame( $this->tempDir . '/uploads/sites/7/2024/01/logo.png', $resolved );
	}

	public function testMainSiteUsesBareUploadsPath(): void {
		$resolver = new UploadsPathResolver( $this->tempDir . '/uploads' );

		$resolved = $resolver->resolve( 1, '2024/02/main-site.png' );

		self::assertSame( $this->tempDir . '/uploads/2024/02/main-site.png', $resolved );
	}

	public function testFallsBackToLegacyBlogsDirLayout(): void {
		$resolver = new UploadsPathResolver( $this->tempDir . '/uploads' );

		$resolved = $resolver->resolve( 9, '2020/03/legacy.png' );

		self::assertSame( $this->tempDir . '/blogs.dir/9/files/2020/03/legacy.png', $resolved );
	}

	public function testReturnsNullWhenFileDoesNotExistAnywhere(): void {
		$resolver = new UploadsPathResolver( $this->tempDir . '/uploads' );

		self::assertNull( $resolver->resolve( 7, '2024/01/missing.png' ) );
	}

	private function removeDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		$items = $items === false ? array() : $items;
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}

			$path = $directory . '/' . $item;
			is_dir( $path ) ? $this->removeDirectory( $path ) : unlink( $path );
		}

		rmdir( $directory );
	}
}
