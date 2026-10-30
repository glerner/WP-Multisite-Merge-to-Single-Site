#!/usr/bin/env php
<?php

/**
 * Renumbers the destination site's admin user away from ID 1
 * (defense-in-depth against username-enumeration scripts that probe
 * `?author=1` -- see src/Destination/AdminIdRenumberer.php docblock),
 * and renames its user_login when `admin_user_login` is set in
 * config.php's destination section (see
 * src/Destination/AdminAccountUpdater.php docblock).
 *
 * Run the renumbering ONCE, right after creating the destination
 * WordPress site and BEFORE setting `admin_user_id` in config.php /
 * running migrate.php against that destination. --rename-login can be
 * run any time: it only renames, and defaults to the configured
 * admin_user_id as its target.
 *
 * The login applied comes from the `admin_user_login` setting in
 * config.php's destination section. If it is not set, a plain
 * renumber run skips the rename and --rename-login aborts rather
 * than inventing a login.
 *
 * Usage:
 *   php bin/harden-admin-id.php --old-id=1 [--new-id=42] [--config=/path/to/config/dir] [--dry-run] [--help]
 *   php bin/harden-admin-id.php --rename-login [--old-id=83] [--config=/path/to/config/dir] [--dry-run] [--help]
 *
 * If --new-id is omitted, a random ID is chosen (see
 * AdminIdRenumberer::pickRandomId() for the default range).
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Destination\AdminAccountUpdater;
use MergeMultisite\Destination\AdminIdRenumberer;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\CliUsage;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );

if ( $args->has( 'help' ) ) {
	CliUsage::printFileDocblock( __FILE__ );
	exit( 0 );
}

CliUsage::printUsage( __FILE__ );

$configDir = $args->get( 'config', $projectRoot . '/config' );
$logger = new Logger( 'info' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$renameOnly = $args->has( 'rename-login' );
$dryRun     = $args->has( 'dry-run' );

// In --rename-login mode the target defaults to the configured
// admin_user_id; in renumber mode --old-id defaults to 1 as before.
$oldId = $args->getInt( 'old-id', $renameOnly ? ( $config->destination->adminUserId ?? 1 ) : 1 );

$destination = new Connection( $config->destination );

try {
	$destination->pdo();

	$existing = $destination->fetchOne(
		sprintf( 'SELECT ID, user_login FROM %susers WHERE ID = :id', $config->destination->tablePrefix ),
		array( 'id' => $oldId )
	);
} catch ( ConnectionException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$renumberer = new AdminIdRenumberer();
$updater    = new AdminAccountUpdater();

if ( $existing === null ) {
	$logger->error( sprintf( 'No user with ID %d found on the destination site.', $oldId ) );
	exit( 1 );
}

// Validate the configured login up front so a bad value fails before
// any renumbering writes happen.
$desiredLogin = $config->destination->adminUserLogin;
if ( $desiredLogin !== null ) {
	$problem = $updater->validateLogin( $desiredLogin );
	if ( $problem !== null ) {
		$logger->error( sprintf( 'config.php destination.admin_user_login "%s": %s.', $desiredLogin, $problem ) );
		exit( 1 );
	}

	$loginOwner = $destination->fetchOne(
		sprintf( 'SELECT ID FROM %susers WHERE user_login = :login', $config->destination->tablePrefix ),
		array( 'login' => $desiredLogin )
	);
	if ( $loginOwner !== null && (int) $loginOwner['ID'] !== $oldId ) {
		$logger->error(
			sprintf( 'Login "%s" already belongs to destination user #%d.', $desiredLogin, (int) $loginOwner['ID'] )
		);
		exit( 1 );
	}
}

$targetId = $oldId;

if ( ! $renameOnly ) {
	$newIdOption = $args->get( 'new-id' );
	$newId = $newIdOption !== null ? (int) $newIdOption : $renumberer->pickRandomId( oldId: $oldId );

	if ( $newId === $oldId ) {
		$logger->error( 'New ID must differ from the old ID.' );
		exit( 1 );
	}

	$conflict = $destination->fetchOne(
		sprintf( 'SELECT ID FROM %susers WHERE ID = :id', $config->destination->tablePrefix ),
		array( 'id' => $newId )
	);

	if ( $conflict !== null ) {
		$logger->error( sprintf( 'A user with ID %d already exists on the destination site.', $newId ) );
		exit( 1 );
	}

	$dmlStatements = $renumberer->buildDmlStatements( $oldId, $newId, $config->destination->tablePrefix );
	$ddlStatements = $renumberer->buildDdlStatements( $newId, $config->destination->tablePrefix );

	$logger->info(
		sprintf(
			'Renumbering user "%s" from ID %d to ID %d.',
			(string) $existing['user_login'],
			$oldId,
			$newId
		)
	);

	if ( $dryRun ) {
		foreach ( array( ...$dmlStatements, ...$ddlStatements ) as $statement ) {
			$logger->info( '[dry-run] ' . $statement );
		}
	} else {
		$pdo = $destination->pdo();

		// Run DML (UPDATEs) inside an atomic transaction.
		// Non-transactional DDL (ALTER TABLE) runs after commit because
		// MySQL implicitly commits around DDL, which would break rollback.
		$pdo->beginTransaction();

		try {
			foreach ( $dmlStatements as $statement ) {
				$pdo->exec( $statement );
			}
			$pdo->commit();
		} catch ( \Throwable $exception ) {
			$pdo->rollBack();
			$logger->error( sprintf( 'Renumbering failed, rolled back: %s', $exception->getMessage() ) );
			exit( 1 );
		}

		try {
			foreach ( $ddlStatements as $statement ) {
				$pdo->exec( $statement );
			}
		} catch ( \Throwable $exception ) {
			$logger->warning( sprintf( 'User renumbered, but AUTO_INCREMENT update failed: %s', $exception->getMessage() ) );
		}
	}

	$targetId = $newId;
}

// Apply the configured login rename. In --rename-login mode this is
// the whole job; after a renumber it lands on the new ID.
if ( $desiredLogin !== null ) {
	if ( (string) $existing['user_login'] === $desiredLogin ) {
		$logger->info( sprintf( 'Admin login is already "%s".', $desiredLogin ) );
	} elseif ( $dryRun ) {
		$logger->info(
			'[dry-run] ' . sprintf(
				'UPDATE %susers SET user_login = \'%s\', user_nicename = \'%s\' WHERE ID = %d',
				$config->destination->tablePrefix,
				$desiredLogin,
				$updater->nicenameFor( $desiredLogin ),
				$targetId
			)
		);
	} else {
		try {
			$updater->renameLogin( $destination, $targetId, $desiredLogin );
		} catch ( \Throwable $exception ) {
			$logger->error( sprintf( 'Login rename failed: %s', $exception->getMessage() ) );
			exit( 1 );
		}

		$logger->info(
			sprintf(
				'Renamed user #%d login "%s" -> "%s".',
				$targetId,
				(string) $existing['user_login'],
				$desiredLogin
			)
		);
	}
} elseif ( $renameOnly ) {
	$logger->error( 'Nothing to rename: set destination.admin_user_login in config.php, or drop --rename-login to renumber.' );
	exit( 1 );
}

if ( ! $renameOnly && ! $dryRun ) {
	$logger->info(
		sprintf(
			'Done. Set \'admin_user_id\' => %d in config.php before running migrate.php against this destination.',
			$targetId
		)
	);
}

exit( 0 );
