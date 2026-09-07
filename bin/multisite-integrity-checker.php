#!/usr/bin/env php
<?php

/**
 * Read-only pre-flight integrity checker for a WordPress multisite
 * network, run before `migrate.php`. See PLAN.md §9.
 *
 * Usage:
 *   php bin/multisite-integrity-checker.php [--strict] [--config=/path/to/config/dir]
 *   php bin/multisite-integrity-checker.php --list-sites
 *   php bin/multisite-integrity-checker.php --list-sites-php > config/sites.php
 *
 * Options:
 *   --strict         Exit non-zero on warnings too, not just errors.
 *   --config         Directory containing config.php/sites.php/etc. Defaults to ../config.
 *   --list-sites     Human-readable site list (blog_id, domain, included/deleted, title).
 *   --list-sites-php Ready-to-paste sites.php PHP source (see SitesPhpExporter) --
 *                     preserves any include/category overrides already in
 *                     sites.php, so re-running this after editing it is safe.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Audit\AuditRunner;
use MergeMultisite\Audit\Checks\ContactPageDiscoveryCheck;
use MergeMultisite\Audit\Checks\MalwareIndicatorCheck;
use MergeMultisite\Audit\Checks\MediaFileCheck;
use MergeMultisite\Audit\Checks\MenuWidgetIntegrityCheck;
use MergeMultisite\Audit\Checks\OrphanedMediaFileCheck;
use MergeMultisite\Audit\Checks\OrphanedMetaCheck;
use MergeMultisite\Audit\Checks\OrphanedPostAuthorCheck;
use MergeMultisite\Audit\Checks\OrphanedPostParentCheck;
use MergeMultisite\Audit\Checks\PluginDataCheck;
use MergeMultisite\Audit\Checks\PodsDetectionCheck;
use MergeMultisite\Audit\Checks\TermCaseCollisionCheck;
use MergeMultisite\Audit\Checks\UserConflictCheck;
use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\SiteSelector;
use MergeMultisite\Migration\SitesPhpExporter;
use MergeMultisite\Report\AuditReportWriter;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );

$configDir = $args->get( 'config', $projectRoot . '/config' );
$logger = new Logger( 'info', $projectRoot . '/var/logs/integrity-checker-' . date( 'Ymd-His' ) . '.log' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$source = new Connection( $config->source );
$siteSelector = new SiteSelector( $source );

if ( $args->has( 'list-sites-php' ) ) {
	// Deliberately prints ONLY the generated PHP (no log lines mixed
	// in), so `... > config/sites.php` produces a valid file.
	echo ( new SitesPhpExporter() )->export( $siteSelector->listAllSites( $config ) );
	exit( 0 );
}

if ( $args->has( 'list-sites' ) ) {
	foreach ( $siteSelector->listAllSites( $config ) as $site ) {
		printf(
			'%-6d %-40s included=%s deleted=%s%s' . PHP_EOL,
			$site->blogId,
			$site->domain,
			$site->included ? 'yes' : 'no',
			$site->deleted ? 'yes' : 'no',
			$site->deleted ? '' : ' title="' . $site->title . '"'
		);
	}
	exit( 0 );
}

$sites = $siteSelector->listIncludedSites( $config );
$logger->info( sprintf( 'Running integrity checks against %d included site(s).', count( $sites ) ) );

$runner = new AuditRunner(
	array(
	new OrphanedPostAuthorCheck(),
	new OrphanedPostParentCheck(),
	new OrphanedMetaCheck(),
	new UserConflictCheck(),
	new MediaFileCheck(),
	new TermCaseCollisionCheck(),
	new PluginDataCheck(),
	new PodsDetectionCheck(),
	new MenuWidgetIntegrityCheck(),
	new ContactPageDiscoveryCheck(),
	new MalwareIndicatorCheck(),
	new OrphanedMediaFileCheck(),
	),
	$logger
);

$findings = $runner->run( $source, $config, $sites );

$paths = ( new AuditReportWriter() )->write(
	$findings,
	$projectRoot . '/var/reports',
	'integrity-' . date( 'Ymd-His' )
);

$errorCount = count( array_filter( $findings, static fn ( AuditFinding $f ): bool => $f->severity === AuditFinding::SEVERITY_ERROR ) );
$warningCount = count( array_filter( $findings, static fn ( AuditFinding $f ): bool => $f->severity === AuditFinding::SEVERITY_WARNING ) );

$logger->info( sprintf( 'Report written to %s and %s', $paths['markdown'], $paths['json'] ) );
$logger->info( sprintf( '%d error(s), %d warning(s).', $errorCount, $warningCount ) );

if ( $errorCount > 0 || ( $args->has( 'strict' ) && $warningCount > 0 ) ) {
	exit( 1 );
}

exit( 0 );
