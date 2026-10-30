<?php

/**
 * Sample runtime configuration for the merge-multisite tools.
 *
 * Copy this file to `config.php` (which is gitignored) and fill in real
 * values. `config.php` is loaded by all three CLI tools via
 * MergeMultisite\Config\ConfigLoader.
 *
 * @package MergeMultisite
 */

return array(

	/*
	 * ---------------------------------------------------------------
	 * Source multisite database.config.sample.php
	 * ---------------------------------------------------------------
	 * `table_prefix` is the network's *base* table prefix (e.g. `wp_`
	 * or `wpRANDOMCHARS_`). Per-site tables are derived from it following
	 * WordPress's own convention: `{table_prefix}{blog_id}_posts` for
	 * every site except the main site (blog_id 1), which uses the bare
	 * `{table_prefix}posts`. Application *never* hardcodes `wp_` — always
	 * read this value from config.
	 * Good convention: always have a '_' suffix.
	 *
	 * `connection` controls how the host/port/socket are determined:
	 *
	 *   'static' (default): use the literal 'host'/'port' below.
	 *
	 *   'lando': ask Lando for the database container's current
	 *   published port -- it changes every time the container is
	 *   recreated, so a hardcodedconfig.sample.php port goes stale. Options:
	 *       'service'      Lando service name (default "database")
	 *       'project_path' dir containing .lando.yml (default: found
	 *                      by walking up from 'uploads_path')
	 *       'lando_binary' path to the lando CLI if not on PATH
	 *
	 *   'local': a site running under Local by Flywheel. On
	 *   macOS/Linux it connects over a unix socket that only exists
	 *   while the site is running. Options:
	 *       'site'       the site's name in Local, OR
	 *       'site_path'  e.g. "~/Local Sites/mysite"
	 *       'sites_json' path to Local's sites.json (if nonstandard)
	 *
	 *   Custom: set 'driver' to the class name of your own
	 *   MergeMultisite\Config\Endpoint\EndpointResolverInterface
	 *   implementation (this file is plain PHP -- `require` the class
	 *   file above the return statement first).
	 */
	'source'      => array(
		'connection'    => 'static',
		'host'          => '127.0.0.1',
		'port'          => 3306,
		'database'      => 'source_multisite',
		'username'      => 'root',
		'password'      => '',
		'charset'       => 'utf8mb4',
		'table_prefix'  => 'wp_',
		// Absolute filesystem path to this site's wp-content/uploads directory.
		'uploads_path'  => '/path/to/source/wp-content/uploads',
	),

	/*
	 * ---------------------------------------------------------------
	 * Destination single-site datconfig.sample.phpabase.
	 * ---------------------------------------------------------------
	 */
	'destination' => array(
		'host'          => '127.0.0.1',
		'port'          => 3306,
		'database'      => 'destination_site',
		'username'      => 'root',
		'password'      => '',
		'charset'       => 'utf8mb4',
		'table_prefix'  => 'wp_',
		'uploads_path'  => '/path/to/destination/wp-content/uploads',

		// The user_id of the admin user you already created on the
		// destination site. Never touched/duplicated by the migrator.
		'admin_user_id' => 1,

		// Optional: the user_login that admin account should have.
		// WordPress refuses login changes everywhere (wp-admin,
		// WP-CLI), so bin/harden-admin-id.php UPDATEs the row directly
		// -- applied on a normal renumbering run, or on its own via
		// `php bin/harden-admin-id.php --rename-login`. user_nicename
		// (the /author/ URL slug) is rewritten to match.
		// 'admin_user_login' => 'renamed-admin-login',

		// Optional: the user_email that admin account should END UP
		// with. During testing the account keeps a local-only address
		// (e.g. *@*.lndo.site) so the client's real inbox is never
		// flooded with dev-site mail; the address below is written only
		// when migrate.php runs with --migrate-admin-emails, i.e. on
		// the finalizing run.
		// 'admin_user_email' => 'webmaster@example.com',
	),

	/*
	 * The single canonical production domain the merged site will live
	 * at, e.g. "https://glerner.com". Every included subsite's own
	 * domain is rewritten to this domain in migrated content/options,
	 * and used to build the redirect map. This is intentionally
	 * independent of whatever `siteurl`/`home` the destination
	 * WordPress install is actually configured with today (e.g. a
	 * local *.lndo.site URL) -- see PLAN.md §2 and §7.6.
	 */
	'destination_url' => 'https://example.com',

	/*
	 * Whether to generate redirect map files (§7.6). Set to false for
	 * a throwaway/local-only destination where redirects are
	 * meaningless; set to true for the run that feeds production.
	 */
	'generate_redirect_files' => true,

	/*
	 * ---------------------------------------------------------------
	 * Migration behavior.
	 * ---------------------------------------------------------------
	 */
	'batch_size' => 200,

	// Audit/report suppression only: post types the audit and
	// integrity checks never scan or report. Does NOT stop migration.
	// OEmbed should be deliberately reconnected

	'audit_excluded_post_types' => array( 'revision', 'oembed_cache', 'deprecated_log' ),

	// Post types that never migrate (leftover/cache/junk only).
	// Everything else -- including form submissions, field
	// definitions, and plugin CPTs -- migrates by default.
	'migration_excluded_post_types' => array( 'oembed_cache' ),

	/*
	 * Audit finding suppressions. Each rule hides findings whose check
	 * name matches "check" (trailing "*" = prefix) AND whose context
	 * equals every other key listed. Examples:
	 *
	 *   ['check' => 'orphaned-post-author.no-author'],
	 *   ['check' => 'plugin-data.*'],
	 *   ['check' => 'plugin-data.orphaned-data', 'plugin' => 'woocommerce'],
	 *   ['check' => 'media-files.missing-file', 'blog_id' => 26],
	 *
	 * A suppressed count is still reported (one line), so filtering is
	 * never fully silent.
	 */
	'suppressions' => array(
		// ['check' => 'orphaned-post-author.no-author'],
	),

	/*
	 * Directories searched (recursively, by filename) when the audit
	 * finds attachment files missing from the source uploads. Matches
	 * are turned into a reviewable bash copy script written next to the
	 * report (var/reports/copy-missing-media-*.sh).
	 */
	'media_search_paths' => array(
		// '~/old-sites',
	),

	// Post statuses always excluded.
	'excluded_post_statuses' => array( 'auto-draft' ),

	// Term case-merge rule: currently only 'most-used' is implemented.
	'term_merge_rule' => 'most-used',

	/*
	 * blog_id of the "main" site -- the one whose variant wins whenever
	 * an element exists on several sites and only one can survive the
	 * merge: term-name case ties (PLAN.md §6), duplicate
	 * wp_template/wp_template_part slugs like 'header' (§9
	 * template-slug-collision check), and site-identity elements
	 * (header/footer, site title) generally. Leave unset for no
	 * preference (ties fall back to lowest blog_id).
	 */
	// 'main_site' => 1,

	/*
	 * Old-author redirection: old user_logins that should NOT be copied
	 * as accounts -- their posts (and author references) are attributed
	 * to the mapped destination user instead. The value is a destination
	 * user_login, or the token 'admin' for the configured
	 * admin_user_id. Handy when an old admin/editor account is redundant
	 * with a destination user (e.g. after renaming the destination
	 * admin's login). Unmapped authors still fall back to admin_user_id.
	 */
	// 'author_mapping' => array(
	// 'old_admin_login' => 'admin',
	// ),

	/*
	 * Spreadsheet output for bin/site-audit.php: 'xlsx' (default; falls
	 * back to CSV when ext-zip is missing), 'csv', or 'both'. The JSON
	 * dump and Markdown summary are always written.
	 */
	'spreadsheet_format' => 'xlsx',

	// Known slugs/paths that should all canonicalize to /contact/.
	'contact_page_paths' => array( '/contact/', '/contact-me/' ),

	'log_level' => 'info', // debug|info|warning|error

	/*
	 * Optional: API token for bin/run-wpscan.php (https://wpscan.com/,
	 * free tier: 25 calls/day). Only used if that script is run
	 * explicitly; not used by any other tool. Leave unset to instead
	 * pass --api-token on the command line each time.
	 */
	// 'wpscan_api_token' => '',
);
