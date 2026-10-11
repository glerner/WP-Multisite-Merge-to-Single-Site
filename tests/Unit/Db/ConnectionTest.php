<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Db;

use MergeMultisite\Db\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Connection::WORDPRESS_SESSION_SQL_MODE is applied on every MySQL
 * connect (pdo()); this pins the contract: session-scoped, strips the
 * two zero-date modes WP data legitimately uses, and leaves strict
 * modes alone so real data problems still surface as errors.
 *
 * @package MergeMultisite
 */
#[CoversClass( Connection::class )]
final class ConnectionTest extends TestCase {

	public function testSessionSqlModeStripsZeroDateModesOnly(): void {
		$statement = Connection::WORDPRESS_SESSION_SQL_MODE;

		self::assertStringContainsString( 'SET SESSION sql_mode', $statement );
		self::assertStringContainsString( 'NO_ZERO_DATE', $statement );
		self::assertStringContainsString( 'NO_ZERO_IN_DATE', $statement );
		self::assertStringNotContainsString( 'STRICT_TRANS_TABLES', $statement );
		self::assertStringNotContainsString( 'STRICT_ALL_TABLES', $statement );
	}
}
