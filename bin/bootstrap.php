<?php

/**
 * Shared bootstrap for the bin/*.php CLI entry points.
 *
 * Loads the Composer autoloader, then points PHP's own error channel at
 * var/logs/php-errors.log. Engine-level output -- warnings, notices,
 * deprecations, uncaught exceptions with their stack traces, and
 * fatals -- lands in a project file instead of only the terminal.
 * Errors still echo to STDERR for interactive use.
 *
 * One gap this cannot cover is a parse error in the entry script
 * itself: PHP compiles the main file before any of this runs, so that
 * single case falls back to the startup error_log (STDERR for CLI).
 * Closing that gap would require PHP_INI_SCAN_DIR or PHPRC set in the
 * environment, which is deliberately not required here.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

$projectRoot = dirname( __DIR__ );

require_once $projectRoot . '/vendor/autoload.php';

$logDir = $projectRoot . '/var/logs';
if ( ! is_dir( $logDir ) ) {
	mkdir( $logDir, 0775, true );
}

// E_ALL, not the distro default E_ALL & ~E_DEPRECATED: deprecations and
// notices are exactly the noise a migration should record.
error_reporting( E_ALL );
ini_set( 'error_log', $logDir . '/php-errors.log' );
// phpcs:ignore WordPress.PHP.IniSet.log_errors_Disallowed -- WP_DEBUG_LOG does not exist outside WordPress.
ini_set( 'log_errors', '1' );
ini_set( 'log_errors_max_len', '0' ); // 0 = no cap, so full stack traces survive.
// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed -- WP_DEBUG_DISPLAY does not exist outside WordPress.
ini_set( 'display_errors', 'stderr' );
ini_set( 'html_errors', '0' );
