#!/usr/bin/env php
<?php

/**
 * Retags stale wp_template/wp_template_part rows on one SOURCE
 * multisite site to that site's active theme.
 *
 * A stale row is a template still owned by a dormant theme
 * (post_name "oldtheme//slug", wp_theme term = oldtheme) while the
 * site runs another theme. Pages that resolve to it render nothing.
 * Retagging the row -- not editing each page -- fixes every page
 * that resolves to that slug at once. See
 * src/Migration/TemplateRetagger.php for what each retag changes.
 *
 * Without --apply the script only prints the plan; every row is
 * annotated so a human can decide:
 *   CONFIRMED PRESENT : an activetheme//slug row already exists, so
 *                       retagging creates a duplicate slug. Decide
 *                       which template should win first, or exclude
 *                       the row with --only.
 *   UNCONFIRMED       : no activetheme//slug row exists, so this row
 *                       becomes the live template for that slug.
 * wp_global_styles rows are reported only -- they are dormant theme
 * customizations, not page templates.
 *
 * Reads and writes go straight to the source database through the
 * configured Connection, so no wp-cli or lando binary is needed.
 * After applying, flush the site's caches (`lando wp cache flush`)
 * so WP re-resolves templates from the updated rows.
 *
 * Usage:
 *   php bin/retag-templates.php --site=<blog_id> [--apply] [--only=<slug,...>] [--config=<dir>] [--help]
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;
use MergeMultisite\Migration\TemplateRetagger;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\CliUsage;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args        = new CliArguments( $argv );

if ( $args->has( 'help' ) ) {
	CliUsage::printFileDocblock( __FILE__ );
	exit( 0 );
}

CliUsage::printUsage( __FILE__ );

$logger = new Logger( 'info' );

$siteId = $args->getInt( 'site' );
if ( $siteId === null || $siteId < 1 ) {
	$logger->error( 'Required: --site=<blog_id> of the SOURCE multisite site to retag.' );
	exit( 1 );
}

$configDir = $args->get( 'config', $projectRoot . '/config' );

try {
	$config = ( new ConfigLoader( $configDir ) )->load();
} catch ( ConfigException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$source   = new Connection( $config->source );
$retagger = new TemplateRetagger( $source, $siteId );

try {
	$plan = $retagger->plan();
} catch ( ConnectionException | \RuntimeException $exception ) {
	$logger->error( $exception->getMessage() );
	exit( 1 );
}

$operations = $plan['operations'];
$active     = $plan['active'];

$logger->info( sprintf( 'Site %d active theme (stylesheet): %s', $siteId, $active ) );

if ( $operations === array() ) {
	$logger->info( 'No stale wp_template/wp_template_part rows found.' );
}

foreach ( $operations as $operation ) {
	$logger->info(
		sprintf(
			'post %d "%s" (%s) -> "%s"',
			$operation['id'],
			$operation['oldName'],
			$operation['postType'],
			$operation['newName']
		)
	);
	if ( $operation['activeExists'] ) {
		$logger->info(
			sprintf( '  CONFIRMED PRESENT: "%s" already exists -- retagging makes a duplicate slug; pick a winner first.', $operation['newName'] )
		);
	} else {
		$logger->info(
			sprintf( '  UNCONFIRMED: no "%s" row -- this row becomes the live template for slug "%s".', $operation['newName'], $operation['slug'] )
		);
	}
	$logger->info(
		sprintf( '  _wp_page_template refs to "%s": %d', $operation['oldName'], $operation['metaRefs'] )
	);
}

foreach ( $plan['dormantGlobalStyles'] as $globalStyles ) {
	$logger->info(
		sprintf( 'note: wp_global_styles post %d "%s" is dormant theme data, not a template -- NOT retagged.', $globalStyles['id'], $globalStyles['name'] )
	);
}

if ( ! $args->has( 'apply' ) ) {
	$logger->info(
		$operations === array()
			? 'Nothing to apply.'
			: sprintf( 'Plan only -- re-run with --apply to retag %d row(s), or --only=<slug,...> to apply a subset.', count( $operations ) )
	);
	exit( 0 );
}

if ( $operations === array() ) {
	exit( 0 );
}

$onlyCsv = $args->get( 'only' );
if ( $onlyCsv !== null ) {
	$wanted     = array_filter( array_map( 'trim', explode( ',', $onlyCsv ) ) );
	$operations = array_values(
		array_filter(
			$operations,
			static fn ( array $operation ) => in_array( $operation['slug'], $wanted, true )
				|| in_array( $operation['oldName'], $wanted, true )
				|| in_array( (string) $operation['id'], $wanted, true )
		)
	);

	if ( $operations === array() ) {
		$logger->error( sprintf( '--only=%s matched none of the planned rows; nothing applied.', $onlyCsv ) );
		exit( 1 );
	}
}

try {
	$retagger->apply( $operations );
} catch ( \Throwable $exception ) {
	$logger->error( sprintf( 'Retag failed, rolled back: %s', $exception->getMessage() ) );
	exit( 1 );
}

$logger->info(
	sprintf(
		'Retagged %d row(s) to theme "%s". Flush the site\'s caches (e.g. `lando wp cache flush --url=<site-url>`) so WP re-resolves templates.',
		count( $operations ),
		$active
	)
);

exit( 0 );
