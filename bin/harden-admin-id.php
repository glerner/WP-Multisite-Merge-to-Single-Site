#!/usr/bin/env php
<?php

/**
 * Renumbers the destination site's admin user away from ID 1
 * (defense-in-depth against username-enumeration scripts that probe
 * `?author=1` -- see src/Destination/AdminIdRenumberer.php docblock).
 *
 * Run this ONCE, right after creating the destination WordPress site
 * and BEFORE setting `admin_user_id` in config.php / running
 * migrate.php against that destination.
 *
 * Usage:
 *   php bin/harden-admin-id.php --old-id=1 [--new-id=42] [--config=/path/to/config/dir] [--dry-run]
 *
 * If --new-id is omitted, a random ID is chosen (see
 * AdminIdRenumberer::pickRandomId() for the default range).
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Destination\AdminIdRenumberer;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );

$configDir = $args->get( 'config', $projectRoot . '/config' );
$logger = new Logger( 'info' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$oldId = $args->getInt( 'old-id', 1 );
$destination = new Connection( $config->destination );
$renumberer = new AdminIdRenumberer();

$existing = $destination->fetchOne(
	sprintf( 'SELECT ID, user_login FROM %susers WHERE ID = :id', $config->destination->tablePrefix ),
	array( 'id' => $oldId )
);

if ( $existing === null ) {
	$logger->error( sprintf( 'No user with ID %d found on the destination site.', $oldId ) );
	exit( 1 );
}

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

$statements = $renumberer->buildStatements( $oldId, $newId, $config->destination->tablePrefix );

$logger->info(
	sprintf(
		'Renumbering user "%s" from ID %d to ID %d.',
		(string) $existing['user_login'],
		$oldId,
		$newId
	)
);

if ( $args->has( 'dry-run' ) ) {
	foreach ( $statements as $statement ) {
		$logger->info( '[dry-run] ' . $statement );
	}
	exit( 0 );
}

$pdo = $destination->pdo();
$pdo->beginTransaction();

try {
	foreach ( $statements as $statement ) {
		$pdo->exec( $statement );
	}
	$pdo->commit();
} catch ( \Throwable $exception ) {
	$pdo->rollBack();
	$logger->error( sprintf( 'Renumbering failed, rolled back: %s', $exception->getMessage() ) );
	exit( 1 );
}

$logger->info(
	sprintf(
		'Done. Set \'admin_user_id\' => %d in config.php before running migrate.php against this destination.',
		$newId
	)
);

exit( 0 );
