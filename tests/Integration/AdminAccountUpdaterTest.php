<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Destination\AdminAccountUpdater;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * AdminAccountUpdater against the SQLite fixture: one UPDATE rewrites
 * user_login and derives a fresh user_nicename.
 *
 * @package MergeMultisite
 */
#[CoversClass( AdminAccountUpdater::class )]
final class AdminAccountUpdaterTest extends SqliteTestCase {

	public function testRenameUpdatesLoginAndDerivesNicename(): void {
		$pdo    = $this->newSqlitePdo( 'wp3_' );
		$userId = WpTestSchema::insertUser(
			$pdo,
			'wp3_users',
			array(
				'user_login'    => 'original-admin',
				'user_nicename' => 'original-admin',
			)
		);

		$updater      = new AdminAccountUpdater();
		$newLogin     = 'Pineapple Headphone';
		$newLoginSlug = $updater->nicenameFor( $newLogin );

		$destination = $this->sqliteConnection( 'wp3_', array( 1 ), '/tmp/uploads', null, $pdo );
		$updater->renameLogin( $destination, $userId, $newLogin );

		$row = $destination->fetchOne(
			'SELECT user_login, user_nicename FROM wp3_users WHERE ID = :id',
			array( 'id' => $userId )
		);

		self::assertNotNull( $row );
		self::assertSame( $newLogin, $row['user_login'] );
		self::assertSame( $newLoginSlug, $row['user_nicename'] );
	}
}
