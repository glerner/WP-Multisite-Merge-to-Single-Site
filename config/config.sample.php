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

return [

    /*
     * ---------------------------------------------------------------
     * Source multisite database.
     * ---------------------------------------------------------------
     * `table_prefix` is the network's *base* table prefix (e.g. `wp_`
     * or `wp3_`). Per-site tables are derived from it following
     * WordPress's own convention: `{table_prefix}{blog_id}_posts` for
     * every site except the main site (blog_id 1), which uses the bare
     * `{table_prefix}posts`. Never hardcode `wp_` — always read this
     * value from config.
     */
    'source'      => [
        'host'          => '127.0.0.1',
        'port'          => 3306,
        'database'      => 'source_multisite',
        'username'      => 'root',
        'password'      => '',
        'charset'       => 'utf8mb4',
        'table_prefix'  => 'wp_',
        // Absolute filesystem path to this site's wp-content/uploads directory.
        'uploads_path'  => '/path/to/source/wp-content/uploads',
    ],

    /*
     * ---------------------------------------------------------------
     * Destination single-site database.
     * ---------------------------------------------------------------
     */
    'destination' => [
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
    ],

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

    // Post types always excluded, regardless of the allow/deny lists below.
    'excluded_post_types' => ['revision'],

    // Post statuses always excluded.
    'excluded_post_statuses' => ['auto-draft'],

    // Term case-merge rule: currently only 'most-used' is implemented.
    'term_merge_rule' => 'most-used',

    // Known slugs/paths that should all canonicalize to /contact/.
    'contact_page_paths' => ['/contact/', '/contact-me/'],

    'log_level' => 'info', // debug|info|warning|error

    /*
     * Optional: API token for bin/run-wpscan.php (https://wpscan.com/,
     * free tier: 25 calls/day). Only used if that script is run
     * explicitly; not used by any other tool. Leave unset to instead
     * pass --api-token on the command line each time.
     */
    // 'wpscan_api_token' => '',
];
