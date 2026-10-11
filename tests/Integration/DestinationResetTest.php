<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Destination\DestinationReset;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Tests\Support\SqliteTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * DestinationReset table listing + drop against the SQLite fixture
 * schema: prefixed tables (WP core shape plus the migration map) are
 * listed and dropped; other schemas' tables are untouched.
 *
 * @package MergeMultisite
 */
#[CoversClass( DestinationReset::class )]
final class DestinationResetTest extends SqliteTestCase {

	public function testListsAndDropsPrefixedTablesOnly(): void {
		$db = $this->sqliteConnection( 'wp_', array( 1 ) );
		( new MigrationTable() )->ensure( $db );
		$db->execute( 'CREATE TABLE not_ours (id INTEGER)' );

		$reset = new DestinationReset();
		$tables = $reset->tablesWithPrefix( $db );

		self::assertContains( 'wp_posts', $tables );
		self::assertContains( 'wp_merge_migration_map', $tables );
		self::assertNotContains( 'not_ours', $tables );

		$dropped = $reset->dropTables( $db, $tables );

		self::assertSame( count( $tables ), $dropped );
		self::assertSame( array(), $reset->tablesWithPrefix( $db ) );
		self::assertTrue( $db->tableExists( 'not_ours' ) );
	}

	public function testDropTablesOnEmptyListIsANoOp(): void {
		$db = $this->sqliteConnection( 'wp_', array( 1 ) );

		self::assertSame( 0, ( new DestinationReset() )->dropTables( $db, array() ) );
		self::assertTrue( $db->tableExists( 'wp_posts' ) );
	}
}
