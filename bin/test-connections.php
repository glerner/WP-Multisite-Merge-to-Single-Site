#!/usr/bin/env php
<?php

/**
 * Minimal test script to verify that both the Source (multisite) and
 * Destination (single-site) databases can be connected to and queried
 * simultaneously within the exact same PHP process.
 *
 * Usage:
 *   php bin/test-connections.php [--config=/path/to/config/dir]
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Support\CliArguments;

$projectRoot = dirname( __DIR__ );
$args        = new CliArguments( $argv );
$configDir   = $args->get( 'config', $projectRoot . '/config' );

echo "=== Merge Multisite: Database Connection Verification ===\n\n";

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	fwrite( STDERR, sprintf( "Configuration error: %s\n", $exception->getMessage() ) );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. Source Database Verification
// ---------------------------------------------------------------------------
echo "[1/2] Connecting to SOURCE database...\n";
$source = new Connection( $config->source );

try {
	$sourcePdo     = $source->pdo();
	$sourceVersion = (string) $source->fetchScalar( 'SELECT @@version' );
	$sourceType    = str_contains( strtolower( $sourceVersion ), 'mariadb' ) ? 'MariaDB' : 'MySQL';
	$sourceTarget  = $config->source->socket !== null
		? sprintf( 'socket %s', $config->source->socket )
		: sprintf( '%s:%d', $config->source->host, $config->source->port );

	printf( "  ✓ Connected to %s (%s version %s)\n", $sourceTarget, $sourceType, $sourceVersion );
	printf( "    Schema: %s | Prefix: %s\n", $config->source->database, $config->source->tablePrefix );

	$blogsTable = $source->networkTable( 'blogs' );
	if ( $source->tableExists( $blogsTable ) ) {
		$siteCount = (int) $source->fetchScalar( sprintf( 'SELECT COUNT(*) FROM %s', $blogsTable ) );
		printf( "    ✓ Found '%s' with %d subsite(s).\n", $blogsTable, $siteCount );
	} else {
		printf( "    ! Note: Network table '%s' does not exist yet (database may need import).\n", $blogsTable );
	}

	$usersTable = $source->networkTable( 'users' );
	if ( $source->tableExists( $usersTable ) ) {
		$userCount = (int) $source->fetchScalar( sprintf( 'SELECT COUNT(*) FROM %s', $usersTable ) );
		printf( "    ✓ Found '%s' with %d user(s).\n", $usersTable, $userCount );
	}

	if ( is_dir( $config->source->uploadsPath ) && is_readable( $config->source->uploadsPath ) ) {
		printf( "    ✓ Source uploads directory exists and is readable (%s).\n", $config->source->uploadsPath );
	} else {
		printf( "    ! WARNING: Source uploads directory not found or not readable: %s\n", $config->source->uploadsPath );
	}
} catch ( ConnectionException $exception ) {
	fwrite( STDERR, sprintf( "  ✗ Source connection failed:\n%s\n", $exception->getMessage() ) );
	exit( 1 );
}

echo "\n";

// ---------------------------------------------------------------------------
// 2. Destination Database Verification
// ---------------------------------------------------------------------------
echo "[2/2] Connecting to DESTINATION database...\n";
$destination = new Connection( $config->destination );

try {
	$destPdo     = $destination->pdo();
	$destVersion = (string) $destination->fetchScalar( 'SELECT @@version' );
	$destType    = str_contains( strtolower( $destVersion ), 'mariadb' ) ? 'MariaDB' : 'MySQL';
	$destTarget  = $config->destination->socket !== null
		? sprintf( 'socket %s', $config->destination->socket )
		: sprintf( '%s:%d', $config->destination->host, $config->destination->port );

	printf( "  ✓ Connected to %s (%s version %s)\n", $destTarget, $destType, $destVersion );
	printf( "    Schema: %s | Prefix: %s\n", $config->destination->database, $config->destination->tablePrefix );

	$destOptions = $config->destination->tablePrefix . 'options';
	if ( $destination->tableExists( $destOptions ) ) {
		$blogname = $destination->fetchScalar(
			sprintf( "SELECT option_value FROM %s WHERE option_name = 'blogname' LIMIT 1", $destOptions )
		);
		printf( "    ✓ Site title: \"%s\"\n", (string) $blogname );
	} else {
		printf( "    ! Note: Table '%s' does not exist yet.\n", $destOptions );
	}

	$destUsers = $config->destination->tablePrefix . 'users';
	if ( $destination->tableExists( $destUsers ) ) {
		$users = $destination->fetchAll( sprintf( 'SELECT ID, user_login, user_email FROM %s LIMIT 5', $destUsers ) );
		printf( "    ✓ Found %d user(s) in '%s':\n", count( $users ), $destUsers );
		foreach ( $users as $u ) {
			printf( "      - ID %d: %s <%s>\n", (int) $u['ID'], (string) $u['user_login'], (string) $u['user_email'] );
		}

		if ( $config->destination->adminUserId !== null ) {
			$adminUser = $destination->fetchOne(
				sprintf( 'SELECT ID, user_login, user_email FROM %s WHERE ID = :id', $destUsers ),
				array( 'id' => $config->destination->adminUserId )
			);
			if ( $adminUser !== null ) {
				printf(
					"    ✓ Configured admin_user_id (%d) matches user \"%s\" <%s>.\n",
					$config->destination->adminUserId,
					(string) $adminUser['user_login'],
					(string) $adminUser['user_email']
				);
			} else {
				printf(
					"    ! WARNING: Configured admin_user_id (%d) not found in '%s'! Run php bin/harden-admin-id.php or update config.php.\n",
					$config->destination->adminUserId,
					$destUsers
				);
			}
		}
	}

	if ( is_dir( $config->destination->uploadsPath ) && is_writable( $config->destination->uploadsPath ) ) {
		printf( "    ✓ Destination uploads directory exists and is writable (%s).\n", $config->destination->uploadsPath );
	} else {
		printf( "    ! WARNING: Destination uploads directory not found or not writable: %s\n", $config->destination->uploadsPath );
	}
} catch ( ConnectionException $exception ) {
	fwrite( STDERR, sprintf( "  ✗ Destination connection failed:\n%s\n", $exception->getMessage() ) );
	exit( 1 );
}

echo "\n=======================================================\n";
echo "SUCCESS: Both Source and Destination databases are accessible\n";
echo "simultaneously in the same PHP process without collision.\n";
echo "=======================================================\n";
exit( 0 );
