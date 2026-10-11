<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Destination;

use MergeMultisite\Config\DatabaseConfig;
use MergeMultisite\Destination\DestinationReset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure command-building for the destination baseline snapshot/restore:
 * mysqldump/mysql argv must carry the resolved endpoint, the table
 * list, and MYSQL_PWD (never --password on the command line).
 *
 * @package MergeMultisite
 */
#[CoversClass( DestinationReset::class )]
final class DestinationResetTest extends TestCase {

	public function testDumpCommandTargetsPrefixTablesAtResolvedEndpoint(): void {
		$command = ( new DestinationReset() )->dumpCommand(
			$this->config(),
			array( 'wpdhweif_posts', 'wpdhweif_options' ),
			'/tmp/baseline.sql'
		);

		self::assertStringContainsString( "MYSQL_PWD='sec ret'", $command );
		self::assertStringContainsString( 'mysqldump', $command );
		self::assertStringContainsString( '--single-transaction', $command );
		self::assertStringContainsString( "'--user=wordpress'", $command );
		self::assertStringContainsString( "'--host=127.0.0.1'", $command );
		self::assertStringContainsString( "'--port=32777'", $command );
		self::assertStringContainsString( "'wpdhweif_posts' 'wpdhweif_options'", $command );
		self::assertStringContainsString( "> '/tmp/baseline.sql'", $command );
		self::assertStringNotContainsString( '--password', $command );
	}

	public function testImportCommandReadsBaselineThroughMysqlClient(): void {
		$command = ( new DestinationReset() )->importCommand( $this->config(), '/tmp/baseline.sql' );

		self::assertStringContainsString( 'mysql', $command );
		self::assertStringContainsString( "'--host=127.0.0.1'", $command );
		self::assertStringContainsString( "< '/tmp/baseline.sql'", $command );
		self::assertStringNotContainsString( '--password', $command );
	}

	public function testSocketConnectionUsesSocketFlagInsteadOfHostPort(): void {
		$config = new DatabaseConfig(
			host: 'localhost',
			port: 3306,
			socket: '/tmp/mysql.sock',
			database: 'wordpress',
			username: 'wordpress',
			password: 'wordpress',
			charset: 'utf8mb4',
			tablePrefix: 'wp_',
			uploadsPath: '/tmp/uploads',
			label: 'destination',
		);

		$command = ( new DestinationReset() )->dumpCommand( $config, array( 'wp_posts' ), '/tmp/base.sql' );

		self::assertStringContainsString( "'--socket=/tmp/mysql.sock'", $command );
		self::assertStringNotContainsString( '--host=', $command );
		self::assertStringNotContainsString( '--port=', $command );
	}

	private function config(): DatabaseConfig {
		return new DatabaseConfig(
			host: '127.0.0.1',
			port: 32777,
			socket: null,
			database: 'wordpress',
			username: 'wordpress',
			password: 'sec ret',
			charset: 'utf8mb4',
			tablePrefix: 'wpdhweif_',
			uploadsPath: '/tmp/uploads',
			label: 'destination',
		);
	}
}
