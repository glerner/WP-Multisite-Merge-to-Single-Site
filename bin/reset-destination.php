#!/usr/bin/env php
<?php

/**
 * Returns the destination database to a known "fresh install" state.
 *
 * Usage:
 *   php bin/reset-destination.php --snapshot [--file=<path>] [--config=<dir>] [--help]
 *       Dump every {prefix}* destination table to the baseline file.
 *       Run this ONCE while the destination is in the documented
 *       starting state (fresh WP install + harden-admin-id).
 *
 *   php bin/reset-destination.php [--file=<path>] [--yes] [--config=<dir>]
 *       Drop every {prefix}* table and re-import the baseline file --
 *       the destination is then byte-for-byte the snapshotted state:
 *       same users (including the renumbered admin_user_id), same
 *       options, no migrated rows, no migration map.
 *
 *   php bin/reset-destination.php --drop-only [--yes] [--config=<dir>]
 *       Drop every {prefix}* table without importing anything, leaving
 *       an empty schema for the documented `lando wp core install`
 *       path (docs/local-environments.md).
 *
 * Files unaffected: wp-config.php, wp-content (plugins/themes/uploads),
 * config/config.php -- a DB reset never touches them. Uploaded media
 * copied by migrate.php stays on disk; MediaMigrator dedups it on the
 * next run, or remove wp-content/uploads/YYYY dirs by hand for a
 * truly clean slate.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Destination\DestinationReset;
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
$baselineFile = (string) $args->get( 'file', $projectRoot . '/' . DestinationReset::DEFAULT_BASELINE_FILE );
$logger = new Logger( 'info' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$destination = new Connection( $config->destination );
$reset = new DestinationReset();

try {
	$destination->pdo();
	$tables = $reset->tablesWithPrefix( $destination );
} catch ( ConnectionException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

// --snapshot: dump current state and exit. Warns when the migration
// map is present -- a baseline should capture a CLEAN destination,
// otherwise the restored map makes migrate.php skip everything.
if ( $args->has( 'snapshot' ) ) {
	if ( $tables === array() ) {
		$logger->error( sprintf( 'No tables with prefix "%s" found -- nothing to snapshot.', $config->destination->tablePrefix ) );
		exit( 1 );
	}

	$mapTable = $config->destination->tablePrefix . 'merge_migration_map';
	if ( in_array( $mapTable, $tables, true ) ) {
		$logger->warning( sprintf( 'Table %s exists: this snapshot contains migrated state. Restoring it makes migrate.php skip already-mapped rows -- only snapshot a clean destination unless that is intended.', $mapTable ) );
	}

	$command = $reset->dumpCommand( $config->destination, $tables, $baselineFile );
	exec( $command, $output, $exitCode );

	if ( $exitCode !== 0 || ! is_file( $baselineFile ) || filesize( $baselineFile ) === 0 ) {
		$logger->error( sprintf( 'mysqldump failed (exit %d): %s', $exitCode, implode( "\n", $output ) ) );
		exit( 1 );
	}

	$logger->info( sprintf( 'Snapshot written: %s (%d tables, %s bytes).', $baselineFile, count( $tables ), number_format( (float) filesize( $baselineFile ), 0 ) ) );
	exit( 0 );
}

// Everything below is destructive (drops {prefix}* tables). Fail
// fast on a missing baseline BEFORE dropping anything.
if ( ! $args->has( 'drop-only' ) && ! is_file( $baselineFile ) ) {
	$logger->error( sprintf( 'Baseline file not found: %s -- run `php bin/reset-destination.php --snapshot` once at the documented starting state, or pass --drop-only.', $baselineFile ) );
	exit( 1 );
}

if ( ! $args->has( 'yes' ) ) {
	fwrite( STDOUT, sprintf( 'About to DROP %d destination table(s) (%s*, including any migration data). Continue? [y/N] ', count( $tables ), $config->destination->tablePrefix ) );
	$answer = fgets( STDIN );
	if ( $answer === false || strtolower( trim( $answer ) ) !== 'y' ) {
		$logger->info( 'Aborted.' );
		exit( 0 );
	}
}

$dropped = $reset->dropTables( $destination, $tables );
$logger->info( sprintf( 'Dropped %d destination table(s).', $dropped ) );

if ( $args->has( 'drop-only' ) ) {
	$logger->info( 'Done. The schema is empty -- reinstall WordPress per docs/local-environments.md (lando wp core install + bin/harden-admin-id.php).' );
	exit( 0 );
}

$command = $reset->importCommand( $config->destination, $baselineFile );
exec( $command, $output, $exitCode );

if ( $exitCode !== 0 ) {
	$logger->error( sprintf( 'mysql import failed (exit %d): %s', $exitCode, implode( "\n", $output ) ) );
	exit( 1 );
}

$restored = count( $reset->tablesWithPrefix( $destination ) );
$logger->info( sprintf( 'Baseline imported: %d table(s) restored. Destination is back to the snapshotted starting state.', $restored ) );
$logger->info( 'Note: uploaded files in wp-content/uploads are untouched (MediaMigrator dedups them on re-run; delete the YYYY dirs for a clean slate).' );

exit( 0 );
