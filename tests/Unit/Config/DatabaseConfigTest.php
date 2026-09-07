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
