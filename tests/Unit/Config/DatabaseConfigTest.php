<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config;

use MergeMultisite\Config\DatabaseConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( DatabaseConfig::class )]
final class DatabaseConfigTest extends TestCase {

	/**
	 * Deliberately NOT "wp_" -- this is the prefix used by most tests
	 * below, so a regression that hardcodes "wp_" anywhere in
	 * DatabaseConfig would fail loudly instead of silently passing.
	 */
	private const TEST_TABLE_PREFIX = 'wp3_';

	/**
	 * A second, differently-shaped prefix used only by
	 * testDoesNotAssumeWpUnderscorePrefix(), to prove the underscore
	 * itself isn't assumed either (e.g. hardcoded string splitting on
	 * the first "_").
	 */
	private const ALTERNATE_TABLE_PREFIX = 'custom_prefix_';

	public function testSiteTableUsesBarePrefixForMainSite(): void {
		$config = $this->makeConfig( self::TEST_TABLE_PREFIX );

		self::assertSame( self::TEST_TABLE_PREFIX . 'posts', $config->siteTable( 'posts', 1 ) );
	}

	public function testSiteTableInsertsBlogIdForSubsites(): void {
		$config = $this->makeConfig( self::TEST_TABLE_PREFIX );

		self::assertSame( self::TEST_TABLE_PREFIX . '7_posts', $config->siteTable( 'posts', 7 ) );
	}

	public function testNetworkTableNeverIncludesBlogId(): void {
		$config = $this->makeConfig( self::TEST_TABLE_PREFIX );

		self::assertSame( self::TEST_TABLE_PREFIX . 'users', $config->networkTable( 'users' ) );
	}

	public function testDoesNotAssumeWpUnderscorePrefix(): void {
		$config = $this->makeConfig( self::ALTERNATE_TABLE_PREFIX );

		self::assertSame( self::ALTERNATE_TABLE_PREFIX . '5_options', $config->siteTable( 'options', 5 ) );
	}

	public function testAllowsSocketOnlyConfigWithoutHost(): void {
		$config = DatabaseConfig::fromArray(
			array(
				'unix_socket'  => '/tmp/mysql.sock',
				'database'     => 'db',
				'username'     => 'root',
				'table_prefix' => 'wp_',
				'uploads_path' => '/tmp/uploads',
			),
			'test'
		);

		self::assertSame( '/tmp/mysql.sock', $config->socket );
		self::assertSame( 'localhost', $config->host );
	}

	public function testParsesAdminUserLogin(): void {
		$config = DatabaseConfig::fromArray(
			array(
				'host'             => '127.0.0.1',
				'database'         => 'db',
				'username'         => 'root',
				'table_prefix'     => 'wp_',
				'uploads_path'     => '/tmp/uploads',
				'admin_user_id'    => 83,
				'admin_user_login' => 'renamed-admin',
				'admin_user_email' => 'admin@example.com',
			),
			'destination'
		);

		self::assertSame( 83, $config->adminUserId );
		self::assertSame( 'renamed-admin', $config->adminUserLogin );
		self::assertSame( 'admin@example.com', $config->adminUserEmail );
	}

	public function testAdminUserLoginDefaultsToNull(): void {
		self::assertNull( $this->makeConfig( 'wp_' )->adminUserLogin );
		self::assertNull( $this->makeConfig( 'wp_' )->adminUserEmail );
	}

	private function makeConfig( string $prefix ): DatabaseConfig {
		return DatabaseConfig::fromArray(
			array(
				'host'         => '127.0.0.1',
				'database'     => 'db',
				'username'     => 'root',
				'table_prefix' => $prefix,
				'uploads_path' => '/tmp/uploads',
			),
			'test'
		);
	}
}
