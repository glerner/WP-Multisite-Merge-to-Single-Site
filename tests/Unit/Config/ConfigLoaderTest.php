<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config;

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ConfigLoader::class )]
final class ConfigLoaderTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		// random_bytes(4) gives ~4.3 billion possible suffixes, so a
		// collision with a leftover directory from a previous (e.g.
		// crashed) test run is negligible; we don't guard against it.
		$this->tempDir = sys_get_temp_dir() . '/merge-multisite-config-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir );
	}

	protected function tearDown(): void {
		$files = glob( $this->tempDir . '/*.php' );
		array_map( 'unlink', $files === false ? array() : $files );
		rmdir( $this->tempDir );
	}

	public function testThrowsWhenConfigFileIsMissing(): void {
		$this->expectException( ConfigException::class );

		( new ConfigLoader( $this->tempDir ) )->load();
	}

	public function testLoadsMinimalValidConfig(): void {
		$this->writeConfigPhp();

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		self::assertSame( 'wp_', $config->source->tablePrefix );
		self::assertSame( 'https://example.com', $config->destinationUrl );
		self::assertSame( 200, $config->batchSize );
		self::assertSame( array( 'revision' ), $config->excludedPostTypes );
		self::assertNull( $config->wpscanApiToken );
	}

	public function testSitesPhpIsOptionalAndDefaultsToEmpty(): void {
		$this->writeConfigPhp();

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		self::assertNull( $config->siteConfigFor( 7 ) );
	}

	public function testSitesPhpEntriesAreIndexedByBlogId(): void {
		$this->writeConfigPhp();

		// This "domain" is the SOURCE subsite's own domain (one entry in
		// the multisite network being merged), which is intentionally
		// unrelated to config.php's "destination_url" (the single domain
		// the merged site will live at) -- deliberately using a
		// different-looking domain here so the two are never confused.
		file_put_contents(
			$this->tempDir . '/sites.php',
			"<?php return [['blog_id' => 7, 'domain' => 'subsite.example.org', 'include' => false]];"
		);

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		$site = $config->siteConfigFor( 7 );
		self::assertNotNull( $site );
		self::assertFalse( $site->include );
	}

	/**
	 * Divergent-options.php is optional (like every config file except
	 * config.php). When absent the check must behave exactly as before
	 * this feature existed -- an empty exclusion list -- so the default
	 * is asserted here rather than assumed.
	 */
	public function testDivergentOptionsPhpIsOptionalAndDefaultsToEmpty(): void {
		$this->writeConfigPhp();

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		self::assertSame( array(), $config->divergentOptionExclusions );
	}

	public function testDivergentOptionsPhpExclusionsAreLoadedAsStringList(): void {
		$this->writeConfigPhp();

		file_put_contents(
			$this->tempDir . '/divergent-options.php',
			"<?php return ['my-plugin-setting', 'another-key'];"
		);

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		self::assertSame( array( 'my-plugin-setting', 'another-key' ), $config->divergentOptionExclusions );
	}

	/**
	 * Plugin-roles.php 'suppressions' merges with config.php rules (both
	 * apply), and the 'check-name' => array(slug, ...) shorthand expands
	 * to one rule per slug with context 'plugin'.
	 */
	public function testPluginRolesSuppressionsMergeAndExpandShorthand(): void {
		$this->writeConfigPhp();
		file_put_contents(
			$this->tempDir . '/plugin-roles.php',
			<<<'PHP'
<?php
return array(
	'suppressions' => array(
		'plugin-data.no-rule' => array( 'ai-engine', 'flamingo' ),
		array( 'check' => 'media-files.missing-file', 'blog_id' => 26 ),
	),
);
PHP
		);

		$config = ( new ConfigLoader( $this->tempDir ) )->load();

		self::assertSame(
			array(
			array(
				'check'  => 'plugin-data.no-rule',
				'plugin' => 'ai-engine',
			),
			array(
				'check'  => 'plugin-data.no-rule',
				'plugin' => 'flamingo',
			),
			array(
				'check'   => 'media-files.missing-file',
				'blog_id' => 26,
			),
			),
			$config->suppressions
		);
	}

	private function writeConfigPhp(): void {
		file_put_contents(
			$this->tempDir . '/config.php',
			<<<'PHP'
<?php
return [
    'source' => [
        'host' => '127.0.0.1',
        'database' => 'source_db',
        'username' => 'root',
        'password' => '',
        'table_prefix' => 'wp_',
        'uploads_path' => '/tmp/source-uploads',
    ],
    'destination' => [
        'host' => '127.0.0.1',
        'database' => 'dest_db',
        'username' => 'root',
        'password' => '',
        'table_prefix' => 'wp_',
        'uploads_path' => '/tmp/dest-uploads',
    ],
    'destination_url' => 'https://example.com',
];
PHP
		);
	}
}
