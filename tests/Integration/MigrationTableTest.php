<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Tests\Support\SqliteTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * CR-401: MigrationTable on SQLite — the dialect-split DDL, the
 * INSERT OR IGNORE idempotency path (the MySQL-only write-side of the
 * migration map), and resume prewarming. This is the first coverage
 * of any write-path SQL in the project.
 *
 * @package MergeMultisite
 */
#[CoversClass( MigrationTable::class )]
final class MigrationTableTest extends SqliteTestCase {

	private Connection $destination;

	private MigrationTable $mapTable;

	protected function setUp(): void {
		parent::setUp();
		$this->destination = $this->sqliteConnection( 'wp_', array( 1 ) );
		$this->mapTable = new MigrationTable();
	}

	public function testEnsureCreatesTableAndTableExistsAgrees(): void {
		$table = $this->mapTable->tableName( $this->destination );

		self::assertFalse( $this->destination->tableExists( $table ) );

		$this->mapTable->ensure( $this->destination );

		self::assertTrue( $this->destination->tableExists( $table ) );
		self::assertSame( 'wp_merge_migration_map', $table );
	}

	public function testRecordAndPrewarmRoundTrip(): void {
		$this->mapTable->ensure( $this->destination );
		$this->mapTable->record( $this->destination, 'post', 2, 15, 101 );

		$idMap = new IdMap();
		$count = $this->mapTable->prewarm( $this->destination, $idMap );

		self::assertSame( 1, $count );
		self::assertSame( 101, $idMap->get( 'post', 2, 15 ) );
	}

	public function testRecordIsIdempotentForSameOrigin(): void {
		$this->mapTable->ensure( $this->destination );
		$this->mapTable->record( $this->destination, 'post', 2, 15, 101 );
		$this->mapTable->record( $this->destination, 'post', 2, 15, 101 );

		$idMap = new IdMap();
		$count = $this->mapTable->prewarm( $this->destination, $idMap );

		// INSERT OR IGNORE: the second record did not error or duplicate.
		self::assertSame( 1, $count );
		self::assertSame( 101, $idMap->get( 'post', 2, 15 ) );
	}

	public function testPrewarmReturnsZeroWhenTableMissing(): void {
		$idMap = new IdMap();

		self::assertSame( 0, $this->mapTable->prewarm( $this->destination, $idMap ) );
	}

	public function testDestinationPrefixNamesTheMapTable(): void {
		$other = $this->sqliteConnection( 'wp3_', array( 1 ) );

		self::assertSame( 'wp3_merge_migration_map', $this->mapTable->tableName( $other ) );
	}
}
