#!/usr/bin/env php
<?php

/**
 * Content/plugin-usage audit tool. Scans every page/post of a site (a
 * single subsite, or an entire multisite) and reports every non-core
 * block type and content-affecting plugin footprint in use, so
 * duplicate plugins (e.g. multiple contact-form plugins) can be
 * identified and consolidated. See PLAN.md §8.
 *
 * Usage:
 *   php bin/site-audit.php --site=7
 *   php bin/site-audit.php --all-sites
 *   php bin/site-audit.php --all-sites --post-types=post,page
 *   php bin/site-audit.php --all-sites --search=cialis,viagra
 *
 * Options:
 *   --site=<blog_id>     Scan only this one subsite.
 *   --all-sites          Scan every non-deleted, included site.
 *   --post-types=a,b,c   Restrict to these post types (default: all).
 *   --search=a,b,c       Instead of the detector pass, do a plain
 *                        case-insensitive content search for these
 *                        terms across the selected sites and write a
 *                        CSV of matching posts (handy for finding
 *                        spam-hack injections like "cialis").
 *   --config=<dir>       Directory containing config.php etc. Defaults to ../config.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\ContentAudit\ContentAuditRow;
use MergeMultisite\ContentAudit\Detectors\BlockDetector;
use MergeMultisite\ContentAudit\Detectors\EcommerceDetector;
use MergeMultisite\ContentAudit\Detectors\FormPluginDetector;
use MergeMultisite\ContentAudit\Detectors\GalleryDetector;
use MergeMultisite\ContentAudit\Detectors\NeedsReviewDetector;
use MergeMultisite\ContentAudit\Detectors\PageBuilderDetector;
use MergeMultisite\ContentAudit\Detectors\SeoPluginDetector;
use MergeMultisite\ContentAudit\Detectors\ShortcodeDetector;
use MergeMultisite\ContentAudit\Detectors\VideoEmbedDetector;
use MergeMultisite\ContentAudit\PostScanner;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Migration\SiteSelector;
use MergeMultisite\Report\ContentAuditReportWriter;
use MergeMultisite\Report\NeedsReviewReportWriter;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );

$configDir = $args->get( 'config', $projectRoot . '/config' );
$logger = new Logger( 'info', $projectRoot . '/var/logs/site-audit-' . date( 'Ymd-His' ) . '.log' );

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

$siteSelector = new SiteSelector( $source );

if ( $args->has( 'all-sites' ) ) {
	$sites = $siteSelector->listIncludedSites( $config );
} elseif ( $args->has( 'site' ) ) {
	$blogId = (int) $args->get( 'site' );
	$sites = array_values(
		array_filter(
			$siteSelector->listAllSites( $config ),
			static fn ( $site ): bool => $site->blogId === $blogId
		)
	);

	if ( $sites === array() ) {
		$logger->error( sprintf( 'No site found with blog_id=%d.', $blogId ) );
		exit( 1 );
	}
} else {
	$logger->error( 'Specify either --site=<blog_id> or --all-sites.' );
	exit( 1 );
}

$postTypesOption = $args->get( 'post-types' );
$postTypes = $postTypesOption !== null
	? array_map( 'trim', explode( ',', $postTypesOption ) )
	: array();

$scanner = new PostScanner( array() );

$searchOption = $args->get( 'search' );
if ( $searchOption !== null ) {
	$needles = array_map( 'trim', explode( ',', $searchOption ) );
	$needles = array_values( array_filter( $needles, static fn ( string $n ): bool => $n !== '' ) );

	if ( $needles === array() ) {
		$logger->error( '--search needs at least one term, e.g. --search=cialis,viagra' );
		exit( 1 );
	}

	$logger->info(
		'NOTE: this is a whole-word keyword sweep, not a thorough spam/malware scan -- '
		. 'generic single words can still false-positive on unrelated legitimate content '
		. '(e.g. a very specific/distinctive term is far more reliable than a common word). '
		. 'Treat matches as a starting point for manual review, not a verdict.'
	);
	$logger->info( sprintf( 'Searching %d site(s) for: %s', count( $sites ), implode( ', ', $needles ) ) );

	$hits = $scanner->searchContent( $source, $sites, $needles, $postTypes );

	$outputPath = $projectRoot . '/var/reports/search-' . date( 'Ymd-His' ) . '.csv';
	if ( ! is_dir( dirname( $outputPath ) ) ) {
		mkdir( dirname( $outputPath ), 0775, true );
	}

	$handle = fopen( $outputPath, 'w' );
	fputcsv( $handle, array( 'blog_id', 'domain', 'post_id', 'post_type', 'post_status', 'post_title', 'slug', 'matched_needle' ), ',', '"', '\\' );

	foreach ( $hits as $hit ) {
		$site = null;
		foreach ( $sites as $candidate ) {
			if ( $candidate->blogId === $hit['blog_id'] ) {
				$site = $candidate;
				break;
			}
		}

		fputcsv(
			$handle,
			array(
				(string) $hit['blog_id'],
				(string) ( $site?->domain ?? '' ),
				(string) $hit['post_id'],
				$hit['post_type'],
				$hit['post_status'],
				$hit['post_title'],
				$hit['post_name'],
				$hit['matched_needle'],
			),
			',',
			'"',
			'\\'
		);
	}

	fclose( $handle );

	$logger->info( sprintf( '%d matching post(s). CSV: %s', count( $hits ), $outputPath ) );
	exit( 0 );
}

$detectors = array(
	new BlockDetector(),
	new ShortcodeDetector(),
	new PageBuilderDetector(),
	new FormPluginDetector(),
	new GalleryDetector(),
	new VideoEmbedDetector(),
	new SeoPluginDetector(),
	new EcommerceDetector(),
	new NeedsReviewDetector(),
);

$logger->info( sprintf( 'Scanning %d site(s) with %d detector(s)...', count( $sites ), count( $detectors ) ) );

$scanner = new PostScanner( $detectors );
$details = $scanner->scanWithDetails( $source, $sites, $postTypes );
$rows = array_map( static fn ( array $d ): ContentAuditRow => $d['row'], $details );

$categories = array_map(
	static fn ( $detector ): string => $detector->category(),
	$detectors
);

$paths = ( new ContentAuditReportWriter() )->write(
	$rows,
	$categories,
	$projectRoot . '/var/reports',
	'site-audit-' . date( 'Ymd-His' )
);

// The "needs review" CSV: only pages carrying a page-builder /
// slideshow / complex-plugin footprint, with original + guessed
// destination URL and raw data dumps for each plugin found.
$needsReviewPath = ( new NeedsReviewReportWriter() )->write(
	$details,
	$config->destinationUrl,
	$projectRoot . '/var/reports',
	'site-audit-needs-review-' . date( 'Ymd-His' )
);

$needsReviewCount = count(
	array_filter(
		$rows,
		static fn ( ContentAuditRow $row ): bool => ( $row->categoryFindings['needs_review'] ?? array() ) !== array()
	)
);

$logger->info( sprintf( 'Scanned %d post(s)/page(s).', count( $rows ) ) );
$logger->info( sprintf( 'CSV: %s', $paths['csv'] ) );
$logger->info( sprintf( 'JSON: %s', $paths['json'] ) );
$logger->info( sprintf( 'Summary: %s', $paths['summary'] ) );
$logger->info( sprintf( '%d page(s) likely needing manual review after migration. CSV: %s', $needsReviewCount, $needsReviewPath ) );

exit( 0 );
