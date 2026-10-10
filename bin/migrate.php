#!/usr/bin/env php
<?php

/**
 * Migration entry point. Runs the implemented phases in dependency
 * order (PLAN.md §5): Users, Terms, Media, then Posts/pages/CPTs.
 * Phases 6-8 (comments, menus/widgets, URL rewriting) are not built
 * yet and will be appended here as they land.
 *
 * Usage:
 *   php bin/migrate.php --move-media-only [--dry-run] [--site=<blog_id>]
 *   php bin/migrate.php [--dry-run] [--site=<blog_id>]
 *
 * Options:
 *   --dry-run          Plan the whole run: resolve every destination
 *                      path and ID, log every planned write and copy,
 *                      but write nothing to the destination DB and
 *                      copy no files. Reports still reflect what WOULD
 *                      happen, so review them before a real run.
 *   --move-media-only  Run only the media phase (PLAN.md §7.2):
 *                      copy/dedupe files and recreate attachment posts,
 *                      without touching users/terms/etc.
 *   --site=<blog_id>   Restrict to one subsite. Default: every included
 *                      (non-deleted) site. Useful for very large
 *                      networks -- run one site at a time.
 *   --config=<dir>     Directory containing config.php etc. Defaults to ../config.
 *
 * Idempotency / resume (PLAN.md §10): every migrated row's origin is
 * recorded in the destination's `{prefix}merge_migration_map` table,
 * and the IdMap is prewarmed from it at startup, so re-running (or
 * resuming after an interruption) skips already-migrated rows instead
 * of double-inserting. There is deliberately no separate --resume flag.
 *
 * DDL caveat: the migration-map table is created BEFORE any batch
 * transaction opens (MySQL implicitly commits around DDL, which would
 * otherwise silently commit pending writes and break rollBack()).
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MediaMigrator;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\PostMigrator;
use MergeMultisite\Migration\Site;
use MergeMultisite\Migration\SiteSelector;
use MergeMultisite\Migration\TermMigrator;
use MergeMultisite\Migration\UploadsPathResolver;
use MergeMultisite\Migration\UserMigrator;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );
$configDir = $args->get( 'config', $projectRoot . '/config' );

$dryRun = $args->has( 'dry-run' );
$mediaOnly = $args->has( 'move-media-only' );

$logger = new Logger( 'info', $projectRoot . '/var/logs/migrate-' . date( 'Ymd-His' ) . '.log' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$source = new Connection( $config->source );
$destination = new Connection( $config->destination );

try {
	$source->pdo();
	$destination->pdo();

	$siteSelector = new SiteSelector( $source );
	if ( $args->has( 'site' ) ) {
		$blogId = (int) $args->get( 'site' );
		$sites = array_values(
			array_filter(
				$siteSelector->listIncludedSites( $config ),
				static fn ( Site $site ): bool => $site->blogId === $blogId
			)
		);
		if ( $sites === array() ) {
			$logger->error( sprintf( 'No included site with blog_id %d found.', $blogId ) );
			exit( 1 );
		}
	} else {
		$sites = $siteSelector->listIncludedSites( $config );
	}

	if ( $sites === array() ) {
		$logger->error( 'No included sites selected -- check config/sites.php.' );
		exit( 1 );
	}
} catch ( ConnectionException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$mapTable = new MigrationTable();
$idMap = new IdMap();

try {
	// The map table is DDL: create it before any batch transaction
	// opens (see docblock). Prewarm the IdMap so a re-run/resume skips
	// already-migrated rows.
	if ( ! $dryRun ) {
		$mapTable->ensure( $destination );
	}
	$prewarmed = $mapTable->prewarm( $destination, $idMap );
	if ( $prewarmed > 0 ) {
		$logger->info( sprintf( 'Resuming: %d already-migrated row(s) found in the migration map.', $prewarmed ) );
	}

	$reports = array();

	if ( ! $mediaOnly ) {
		$reports['users'] = ( new UserMigrator() )->migrate( $source, $destination, $config, $sites, $idMap, $mapTable, $dryRun, $logger );
		$reports['terms'] = ( new TermMigrator() )->migrate( $source, $destination, $config, $sites, $idMap, $mapTable, $dryRun, $logger );
	}

	$reports['media'] = ( new MediaMigrator(
		new UploadsPathResolver( $config->source->uploadsPath ),
		$config->destination->uploadsPath
	) )->migrate( $source, $destination, $config, $sites, $idMap, $mapTable, $dryRun, $logger );

	if ( ! $mediaOnly ) {
		$reports['posts'] = ( new PostMigrator() )->migrate( $source, $destination, $config, $sites, $idMap, $mapTable, $dryRun, $logger );
	}

	if ( ! $dryRun ) {
		$idMap->save( $projectRoot . '/var/state/idmap-' . date( 'Ymd-His' ) . '.json' );
	}

	// Always write a machine-readable run report (dry-run included, so
	// the plan can be reviewed before committing to a real run).
	$reportDir = $projectRoot . '/var/reports';
	if ( ! is_dir( $reportDir ) ) {
		mkdir( $reportDir, 0775, true );
	}
	$reportPath = $reportDir . '/migrate-' . date( 'Ymd-His' ) . ( $dryRun ? '-dryrun' : '' ) . '.json';
	file_put_contents(
		$reportPath,
		json_encode(
			array(
				'run'      => array(
					'dry_run'    => $dryRun,
					'media_only' => $mediaOnly,
					'sites'      => array_map(
						static fn ( Site $site ): array => array(
							'blog_id' => $site->blogId,
							'domain'  => $site->domain,
						),
						$sites
					),
				),
				'reports'  => $reports,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);

	$logger->info( sprintf( 'Migration run complete%s; report: %s', $dryRun ? ' (dry run)' : '', $reportPath ) );
} catch ( \Throwable $exception ) {
	$logger->error( sprintf( 'Migration aborted: %s', $exception->getMessage() ) );
	exit( 1 );
}
