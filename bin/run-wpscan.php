#!/usr/bin/env php
<?php

/**
 * Thin, optional wrapper around the external `wpscan` CLI (a Ruby
 * gem, https://wpscan.com/wordpress-cli-scanner/ -- NOT bundled with
 * this project and not a PHP library) to check a live site for known
 * vulnerable core/plugin/theme versions.
 *
 * This scans a live HTTP(S) URL, not a database, so it is independent
 * of the source/destination DB connections the other tools use.
 *
 * Requires:
 *   - `wpscan` installed and on PATH (`gem install wpscan`)
 *   - A WPScan API token (free tier: 25 calls/day) -- pass via
 *     --api-token, or set `wpscan_api_token` in config.php
 *
 * Usage:
 *   php bin/run-wpscan.php --url=https://example.com [--api-token=...] [--config=/path/to/config/dir]
 *
 * The free tier's 25-calls/day limit is why this only ever scans ONE
 * URL per invocation -- it is deliberately not wired up to loop over
 * every subsite automatically.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\ConfigLoader;
use MergeMultisite\Support\CliArguments;
use MergeMultisite\Support\Logger;

$projectRoot = dirname( __DIR__ );
$args = new CliArguments( $argv );
$logger = new Logger( 'info' );

$url = $args->get( 'url' );
if ( $url === null ) {
	$logger->error( 'Usage: php bin/run-wpscan.php --url=https://example.com [--api-token=...]' );
	exit( 1 );
}

$wpscanPath = trim( (string) shell_exec( 'command -v wpscan 2>/dev/null' ) );
if ( $wpscanPath === '' ) {
	$logger->error( 'The "wpscan" command was not found on PATH. Install it with: gem install wpscan' );
	exit( 1 );
}

$apiToken = $args->get( 'api-token' );
if ( $apiToken === null ) {
	$configDir = $args->get( 'config', $projectRoot . '/config' );
	try {
		$config = ( new ConfigLoader( $configDir ) )->load();
		$apiToken = $config->wpscanApiToken;
	} catch ( ConfigException ) {
		$apiToken = null;
	}
}

$outputPath = $projectRoot . '/var/reports/wpscan-' . date( 'Ymd-His' ) . '.json';
if ( ! is_dir( dirname( $outputPath ) ) ) {
	mkdir( dirname( $outputPath ), 0775, true );
}

$command = array( $wpscanPath, '--url', $url, '--format', 'json', '--output', $outputPath, '--no-banner' );
if ( $apiToken !== null ) {
	$command[] = '--api-token';
	$command[] = $apiToken;
}

$logger->info( sprintf( 'Running: %s (output redacted; token not logged)', implode( ' ', array( $wpscanPath, '--url', $url, '--format', 'json', '...' ) ) ) );

$escapedCommand = implode( ' ', array_map( 'escapeshellarg', $command ) );
exec( $escapedCommand, $outputLines, $exitCode );

if ( ! is_file( $outputPath ) ) {
	$logger->error( 'wpscan did not produce an output file; see its own console output above for details.' );
	exit( $exitCode );
}

$results = json_decode( (string) file_get_contents( $outputPath ), true );
if ( ! is_array( $results ) ) {
	$logger->warning( 'wpscan output could not be parsed as JSON; see ' . $outputPath . ' directly.' );
	exit( 0 );
}

$logger->info( 'Full results: ' . $outputPath );

$vulnerabilities = $results['version']['vulnerabilities'] ?? array();
if ( is_array( $vulnerabilities ) && $vulnerabilities !== array() ) {
	$logger->warning( sprintf( '%d known WordPress core vulnerability finding(s) reported.', count( $vulnerabilities ) ) );
}

foreach ( ( $results['plugins'] ?? array() ) as $pluginSlug => $pluginData ) {
	$pluginVulnerabilities = $pluginData['vulnerabilities'] ?? array();
	if ( is_array( $pluginVulnerabilities ) && $pluginVulnerabilities !== array() ) {
		$logger->warning( sprintf( 'Plugin "%s": %d known vulnerability finding(s).', (string) $pluginSlug, count( $pluginVulnerabilities ) ) );
	}
}

exit( 0 );
