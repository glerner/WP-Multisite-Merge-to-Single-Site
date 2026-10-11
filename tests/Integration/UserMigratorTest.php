<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\UserMigrator;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * UserMigrator against the SQLite fixture: role-gated migration, the
 * same-login/same-email link, the hard-conflict skip, and the
 * author_mapping alias (old login -> destination login or the 'admin'
 * token) which folds an old account's posts onto a destination user
 * without copying the account.
 *
 * @package MergeMultisite
 */
#[CoversClass( UserMigrator::class )]
final class UserMigratorTest extends SqliteTestCase {

	private Connection $source;

	private Connection $destination;

	private MigrationTable $mapTable;

	private IdMap $idMap;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->sqliteConnection( 'wp_', array( 1 ) );
		// Destination admin (for the 'admin' mapping token).
		$this->destination = $this->sqliteConnection( 'wp_', array( 1 ), '/tmp/uploads', adminUserId: 83 );
		$this->mapTable = new MigrationTable();
		$this->mapTable->ensure( $this->destination );
		$this->idMap = new IdMap();
	}

	public function testMigratesUsersWithResolvedRoles(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities(
			array(
			1 => 'editor',
			2 => 'author',
			)
		);

		$report = $this->runMigrate();

		self::assertSame( 2, $report['migrated'] );
		self::assertSame( 0, count( $report['conflicts'] ) );
		self::assertSame( 0, count( $report['aliased'] ) );
		self::assertSame( 2, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$this->destination->networkTable( 'users' )}" ) );

		// Role was written using the DESTINATION prefix.
		$editorMeta = $this->destination->fetchScalar(
			"SELECT meta_value FROM {$this->destination->networkTable( 'usermeta' )} WHERE user_id = 1 AND meta_key = 'wp_capabilities'"
		);
		self::assertSame( serialize( array( 'editor' => true ) ), $editorMeta );
	}

	public function testSameLoginAndEmailLinkToExistingDestinationUser(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities( array( 1 => 'editor' ) );

		// Destination already has the same person (same login + email).
		WpTestSchema::insertUser(
			$this->destination->pdo(),
			$this->destination->networkTable( 'users' ),
			array(
			'ID' => 500,
			'user_login' => 'alice',
			'user_email' => 'alice@example.test',
			)
		);

		$report = $this->runMigrate();

		self::assertSame( 0, $report['migrated'] );
		self::assertCount( 1, $report['linked'] );
		self::assertSame( 500, $this->idMap->get( 'user', 0, 1 ) );
	}

	public function testSameLoginDifferentEmailIsAConflict(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities( array( 1 => 'editor' ) );

		WpTestSchema::insertUser(
			$this->destination->pdo(),
			$this->destination->networkTable( 'users' ),
			array(
			'ID' => 500,
			'user_login' => 'alice',
			'user_email' => 'other@example.test',
			)
		);

		$report = $this->runMigrate();

		self::assertSame( 0, $report['migrated'] );
		self::assertCount( 1, $report['conflicts'] );
		self::assertNull( $this->idMap->get( 'user', 0, 1 ) );
	}

	public function testAuthorMappingToAdminTokenAliasesWithoutCopying(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities(
			array(
			1 => 'editor',
			2 => 'author',
			)
		);

		$report = $this->runMigrate(
			configOverrides: array(
				'author_mapping' => array( 'alice' => 'admin' ),
			)
		);

		self::assertSame( 1, $report['migrated'] ); // bob only.
		self::assertCount( 1, $report['aliased'] );
		self::assertSame( 83, $this->idMap->get( 'user', 0, 1 ) );
		self::assertSame(
			83,
			(int) $this->destination->fetchScalar(
				"SELECT dest_id FROM {$this->mapTable->tableName( $this->destination )} WHERE entity_type = 'user' AND source_id = '1'"
			)
		);
		// The aliased account is not copied.
		self::assertSame( 1, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$this->destination->networkTable( 'users' )}" ) );
	}

	public function testAuthorMappingToDestinationLogin(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities(
			array(
			1 => 'editor',
			2 => 'author',
			)
		);

		WpTestSchema::insertUser(
			$this->destination->pdo(),
			$this->destination->networkTable( 'users' ),
			array(
			'ID' => 900,
			'user_login' => 'maintainer',
			'user_email' => 'm@example.test',
			)
		);

		$report = $this->runMigrate(
			configOverrides: array( 'author_mapping' => array( 'alice' => 'maintainer' ) )
		);

		self::assertSame( 1, $report['migrated'] );
		self::assertSame( 900, $this->idMap->get( 'user', 0, 1 ) );
	}

	public function testAuthorMappingWithMissingTargetMigratesNormally(): void {
		$this->seedSourceUsers();
		$this->seedSourceCapabilities( array( 1 => 'editor' ) );

		$report = $this->runMigrate(
			configOverrides: array( 'author_mapping' => array( 'alice' => 'no-such-user' ) )
		);

		// Unresolvable target: warned, account migrates normally.
		self::assertSame( 1, $report['migrated'] );
		self::assertSame( 0, count( $report['aliased'] ) );
		self::assertNotNull( $this->idMap->get( 'user', 0, 1 ) );
	}

	private function seedSourceUsers(): void {
		$usersTable = $this->source->networkTable( 'users' );
		WpTestSchema::insertUser(
			$this->source->pdo(),
			$usersTable,
			array(
			'ID' => 1,
			'user_login' => 'alice',
			'user_email' => 'alice@example.test',
			'display_name' => 'Alice',
			)
		);
		WpTestSchema::insertUser(
			$this->source->pdo(),
			$usersTable,
			array(
			'ID' => 2,
			'user_login' => 'bob',
			'user_email' => 'bob@example.test',
			'display_name' => 'Bob',
			)
		);
	}

	/**
	 * @param array<int, string> $rolesByUserId user_id => role.
	 */
	private function seedSourceCapabilities( array $rolesByUserId ): void {
		foreach ( $rolesByUserId as $userId => $role ) {
			WpTestSchema::insertUserMeta(
				$this->source->pdo(),
				$this->source->networkTable( 'usermeta' ),
				array(
					'user_id' => $userId,
					'meta_key' => 'wp_capabilities',
					'meta_value' => serialize( array( $role => true ) ),
				)
			);
		}
	}

	private function runMigrate( array $configOverrides = array() ): array {
		// The config's destination must match the migrated-into
		// connection (its adminUserId powers the 'admin' mapping token).
		$overrides = array_merge(
			array( 'destination' => $this->destination->config ),
			$configOverrides
		);

		return ( new UserMigrator() )->migrate(
			$this->source,
			$this->destination,
			$this->testConfig( overrides: $overrides ),
			array( new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ) ),
			$this->idMap,
			$this->mapTable,
			false,
			new Logger( 'error' )
		);
	}
}
