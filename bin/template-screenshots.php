<?php

/**
 * Screenshots every included site's header/footer/template elements
 * using shot-scraper, into var/reports/template-shots/{blogId}-{slug}.png
 * (overwritten each run). See docs/making-screenshots.md.
 *
 * Each template is shot on a page that actually renders it (resolved via
 * show_on_front/page options, WooCommerce page IDs, or a sample post);
 * wp_template_part elements are shot on a page whose template includes
 * them. Rows customized under an inactive theme are reported as retag
 * candidates, not screenshotted.
 *
 * Usage:
 *   php bin/template-screenshots.php [--config=/path/to/config/dir] [--site=<blog_id>] [--dry-run]
 *
 * Options:
 *   --config    Directory containing config.php/sites.php/etc. Defaults to ../config.
 *   --site      Limit to one blog_id.
 *   --dry-run   Write the shots.yml only; don't invoke shot-scraper.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Migration\SiteSelector;
use MergeMultisite\Migration\TemplateContextCollector;
use MergeMultisite\Migration\TemplateInventory;
use MergeMultisite\Migration\TemplateShotPlan;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );

$configDir = $args->get( 'config', $projectRoot . '/config' );
$logger = new Logger( 'info', $projectRoot . '/var/logs/template-screenshots-' . date( 'Ymd-His' ) . '.log' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$source = new Connection( $config->source );

try {
	$source->pdo();
} catch ( ConnectionException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$sites = ( new SiteSelector( $source ) )->listIncludedSites( $config );

$siteFilter = $args->get( 'site' );
if ( $siteFilter !== null ) {
	$siteFilter = (int) $siteFilter;
	$sites = array_values( array_filter( $sites, static fn ( $site ): bool => $site->blogId === $siteFilter ) );
	if ( $sites === array() ) {
		$logger->error( sprintf( 'Site %d is not in the included set.', $siteFilter ) );
		exit( 1 );
	}
}

$inventory = ( new TemplateInventory() )->collect( $source, $sites );
$collector = TemplateContextCollector::fromUploadsPath( $config->source->uploadsPath );
$plan = new TemplateShotPlan();
$outputDir = $projectRoot . '/var/reports/template-shots';

$entries = array();
$totalSkipped = array();
$totalStale = array();
foreach ( $sites as $site ) {
	$rows = $inventory[ $site->blogId ] ?? array();
	$context = $collector->collect( $source, $site, $rows );

	if ( $context->templateOption !== ''
		&& $context->templateOption !== $context->stylesheet
		&& $context->templateOption !== $context->parentStylesheet ) {
		$logger->warning(
			sprintf(
				'Site %d `template` option mismatch: option="%s" but the active theme header resolves parent="%s" -- the theme header wins.',
				$site->blogId,
				$context->templateOption,
				$context->parentStylesheet ?? 'none'
			)
		);
	}

	$result = $plan->shotsFor( $site, $rows, $context );
	foreach ( $result['shots'] as $shot ) {
		$entries[] = $shot + array(
			'output' => $outputDir . '/' . $site->blogId . '-' . $shot['slug'] . '.png',
		);
	}
	foreach ( $result['skipped'] as $slug => $reason ) {
		$totalSkipped[] = sprintf( 'site %d "%s" -- %s', $site->blogId, $slug, $reason );
	}
	foreach ( $result['stale'] as $slug => $theme ) {
		$totalStale[] = sprintf( 'site %d "%s" (customized under inactive theme "%s")', $site->blogId, $slug, $theme );
	}
}

if ( $entries === array() ) {
	$logger->info( 'No shots to take.' );
	exit( 0 );
}

if ( ! is_dir( $outputDir ) ) {
	mkdir( $outputDir, 0775, true );
}

$yamlPath = $outputDir . '/shots.yml';
file_put_contents( $yamlPath, $plan->toYaml( $entries ) );
$logger->info( sprintf( 'Wrote %d shot(s) to %s', count( $entries ), $yamlPath ) );

if ( $totalSkipped !== array() ) {
	$logger->info( 'Skipped (no shot planned):' . "\n  - " . implode( "\n  - ", $totalSkipped ) );
}
if ( $totalStale !== array() ) {
	$logger->info( 'Inactive-theme customizations (retag the row to the active stylesheet to restore):' . "\n  - " . implode( "\n  - ", $totalStale ) );
}

if ( $args->has( 'dry-run' ) ) {
	exit( 0 );
}

$shotScraper = trim( (string) shell_exec( 'command -v shot-scraper' ) );
if ( $shotScraper === '' ) {
	$logger->error( 'shot-scraper not found on PATH. Install: pipx install shot-scraper && shot-scraper install (docs/making-screenshots.md).' );
	exit( 1 );
}

// One shot-scraper call per entry: a missing element (e.g. a classic
// theme with no <header> tag) fails only that shot, not the batch.
$failures = array();
foreach ( $entries as $entry ) {
	$cmd = 'shot-scraper ' . escapeshellarg( $entry['url'] )
		. ' -o ' . escapeshellarg( $entry['output'] )
		. ' --retina';
	if ( $entry['selector'] !== null ) {
		$cmd .= ' --selector ' . escapeshellarg( $entry['selector'] )
			// Element shots wait for the selector to be VISIBLE; a part
			// that renders as a hidden/empty element (e.g. a stale
			// customization left the live part empty) would otherwise
			// burn 30s per shot on Playwright's stability wait. 10s is
			// plenty for a local site to paint.
			. ' --timeout 10000';
	}

	exec( $cmd . ' 2>&1', $output, $exitCode );
	if ( $exitCode !== 0 ) {
		$failures[] = $entry['output'];
		$logger->warning( sprintf( 'Shot failed: %s (%s)', $entry['output'], implode( ' ', $output ) ) );
	}
	$output = array();
}

$logger->info( sprintf( 'Done: %d of %d screenshot(s) written to %s', count( $entries ) - count( $failures ), count( $entries ), $outputDir ) );

if ( $failures !== array() ) {
	exit( 1 );
}
