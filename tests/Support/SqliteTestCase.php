<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Support;

use MergeMultisite\Config\DatabaseConfig;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for SQLite-backed tests of DB-bound classes (CR-401).
 *
 * Every test here creates and owns its own in-memory database — the
 * configured Source/Destination databases are never touched. If the
 * pdo_sqlite extension is missing the whole test is skipped with a
 * reason naming the package to install, so the suite still passes on
 * machines without it while running everywhere the extension exists.
 *
 * @package MergeMultisite
 */
abstract class SqliteTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			self::markTestSkipped(
				'pdo_sqlite extension is not installed — install php8.4-sqlite3 (or the matching php*-sqlite3 for your CLI) to run SQLite-backed tests.'
			);
		}
	}

	/**
	 * A fresh in-memory SQLite PDO with the WordPress-shaped schema
	 * created for the given prefix + blog ids.
	 *
	 * @param int[] $blogIds
	 */
	protected function newSqlitePdo( string $prefix = 'wp_', array $blogIds = array( 1 ) ): PDO {
		$pdo = new PDO( 'sqlite::memory:' );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$pdo->exec( 'PRAGMA foreign_keys = OFF' );

		WpTestSchema::create( $pdo, $prefix, $blogIds );

		return $pdo;
	}

	/**
	 * A Connection wrapping a fresh in-memory schema, with a
	 * DatabaseConfig whose prefix/uploads/admin match the fixture so
	 * `siteTable()`/`networkTable()`/author fallback behave like the
	 * real thing. Pass `$pdo` to wrap an already-created schema
	 * instead of building a new one.
	 *
	 * @param int[] $blogIds
	 */
	protected function sqliteConnection(
		string $prefix = 'wp_',
		array $blogIds = array( 1 ),
		string $uploadsPath = '/tmp/uploads',
		?int $adminUserId = null,
		?PDO $pdo = null
	): Connection {
		$pdo ??= $this->newSqlitePdo( $prefix, $blogIds );

		return Connection::forTesting(
			$pdo,
			new DatabaseConfig(
				host: 'localhost',
				port: 3306,
				socket: null,
				database: 'test',
				username: 'test',
				password: '',
				charset: 'utf8mb4',
				tablePrefix: $prefix,
				uploadsPath: $uploadsPath,
				adminUserId: $adminUserId,
				label: 'test',
			)
		);
	}

	/**
	 * Recursively delete a temporary directory tree created by a test.
	 */
	protected function removeDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * A MergeConfig with test-appropriate defaults for migrator tests
	 * (small batches so batching boundaries are exercised, a fixed
	 * destination URL, and the standard built-in post-type exclusions
	 * kept empty so tests control exactly what migrates).
	 */
	protected function testConfig(
		string $destinationUrl = 'https://example.test',
		array $overrides = array()
	): MergeConfig {
		$defaults = array(
			'source'               => $this->dummyDatabaseConfig( 'source' ),
			'destination'          => $this->dummyDatabaseConfig( 'destination' ),
			'destination_url'      => $destinationUrl,
			'generate_redirect_files' => false,
			'batch_size'           => 2,
			'audit_excluded_post_types' => array(),
			'excluded_post_statuses' => array(),
			'term_merge_rule'      => 'most-used',
			'contact_page_paths'   => array( '/contact/', '/contact-me/' ),
			'log_level'            => 'error',
			'sites'                => array(),
			'plugin_option_rules'  => array(),
			'term_overrides'       => array(),
		);

		$merged = array_merge( $defaults, $overrides );

		return new MergeConfig(
			source: $merged['source'],
			destination: $merged['destination'],
			destinationUrl: $merged['destination_url'],
			generateRedirectFiles: $merged['generate_redirect_files'],
			batchSize: $merged['batch_size'],
			auditExcludedPostTypes: $merged['audit_excluded_post_types'],
			migrationExcludedPostTypes: $merged['migration_excluded_post_types'] ?? array(),
			excludedPostStatuses: $merged['excluded_post_statuses'],
			termMergeRule: $merged['term_merge_rule'],
			contactPagePaths: $merged['contact_page_paths'],
			logLevel: $merged['log_level'],
			sites: $merged['sites'],
			pluginOptionRules: $merged['plugin_option_rules'],
			termOverrides: $merged['term_overrides'],
		);
	}

	private function dummyDatabaseConfig( string $label ): DatabaseConfig {
		return new DatabaseConfig(
			host: 'localhost',
			port: 3306,
			socket: null,
			database: 'test-' . $label,
			username: 'test',
			password: '',
			charset: 'utf8mb4',
			tablePrefix: 'wp_',
			uploadsPath: '/tmp/uploads',
			label: $label,
		);
	}
}
