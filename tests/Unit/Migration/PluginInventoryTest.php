<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Migration;

use MergeMultisite\Migration\PluginInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( PluginInventory::class )]
final class PluginInventoryTest extends TestCase {

	private string $wpContentDir;

	protected function setUp(): void {
		$this->wpContentDir = sys_get_temp_dir() . '/merge-multisite-plugin-inventory-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->wpContentDir . '/plugins/real-plugin', 0775, true );
		mkdir( $this->wpContentDir . '/plugins/.git', 0775, true );
		mkdir( $this->wpContentDir . '/plugins/vendor', 0775, true );
		mkdir( $this->wpContentDir . '/mu-plugins', 0775, true );

		file_put_contents(
			$this->wpContentDir . '/plugins/real-plugin/real-plugin.php',
			"<?php\n/**\n * Plugin Name: Real Plugin\n */\n"
		);
		// Loose single-file plugin directly in plugins/.
		file_put_contents(
			$this->wpContentDir . '/plugins/single-file-plugin.php',
			"<?php\n/**\n * Plugin Name: Single File Plugin\n */\n"
		);
		// Not a plugin: a helper script with no Plugin Name header.
		file_put_contents(
			$this->wpContentDir . '/plugins/helper-script.php',
			"<?php\n// just a script\n"
		);
		// vendor/ contains PHP files but no Plugin Name header anywhere -- not a plugin.
		file_put_contents( $this->wpContentDir . '/plugins/vendor/autoload.php', "<?php\n// composer autoloader\n" );

		file_put_contents(
			$this->wpContentDir . '/mu-plugins/000-prime-mover-constants.php',
			"<?php\n// mu-plugin, no header required\ndefine('X', 1);\n"
		);
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->wpContentDir );
	}

	public function testInstalledSlugsFindsRealPluginDirectory(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins' );

		self::assertContains( 'real-plugin', $inventory->installedSlugs() );
	}

	public function testInstalledSlugsFindsSingleFilePluginWithHeader(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins' );

		self::assertContains( 'single-file-plugin', $inventory->installedSlugs() );
	}

	public function testInstalledSlugsExcludesFilesWithoutPluginHeader(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins' );

		self::assertNotContains( 'helper-script', $inventory->installedSlugs() );
	}

	public function testInstalledSlugsExcludesDotDirectoriesAndVendor(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins' );

		$slugs = $inventory->installedSlugs();

		self::assertNotContains( '.git', $slugs );
		self::assertNotContains( 'vendor', $slugs );
	}

	public function testMustUseSlugsFindsTopLevelPhpFiles(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins', $this->wpContentDir . '/mu-plugins' );

		self::assertSame( array( '000-prime-mover-constants' ), $inventory->mustUseSlugs() );
	}

	public function testMustUseSlugsReturnsEmptyWhenDirectoryMissing(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins', $this->wpContentDir . '/no-such-dir' );

		self::assertSame( array(), $inventory->mustUseSlugs() );
	}

	public function testMustUseSlugsReturnsEmptyWhenPathNotConfigured(): void {
		$inventory = new PluginInventory( $this->wpContentDir . '/plugins' );

		self::assertSame( array(), $inventory->mustUseSlugs() );
	}

	public function testFromUploadsPathDerivesBothPluginsAndMuPluginsAsSiblings(): void {
		$inventory = PluginInventory::fromUploadsPath( $this->wpContentDir . '/uploads' );

		self::assertContains( 'real-plugin', $inventory->installedSlugs() );
		self::assertSame( array( '000-prime-mover-constants' ), $inventory->mustUseSlugs() );
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
