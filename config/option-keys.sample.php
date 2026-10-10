<?php

/**
 * Sample per-plugin `wp_options` migration EXCEPTION list.
 *
 * Copy to `option-keys.php` (gitignored, but this sample is safe to
 * keep committed/edited directly since it never contains credentials).
 *
 * Migration is default-on: every option on every included site
 * migrates unless a rule here -- or the migrator's built-in
 * never-migrate list of per-install/transient core options -- drops
 * it. A dropped plugin setting is how merged sites silently break; a
 * migrated one is cheap. This file exists to say "not this one" or
 * "only partly", not to allowlist. Each entry is keyed by plugin slug
 * (as it appears in `wp plugin list` / the plugin's own directory
 * name). See PLAN.md §7.5 for the rationale behind each decision.
 *
 * `mode`:
 *   - 'include'        Option key(s) are copied as-is (through the
 *                       standard SerializedDataRewriter pass). This is
 *                       the default behavior anyway; an entry serves
 *                       as documentation that the decision was made.
 *   - 'include_partial' Only specific sub-keys are copied; see
 *                       `exclude_subkeys` for what is stripped out.
 *   - 'exclude'         Plugin is recognized but its data is
 *                       intentionally never migrated. Still reported
 *                       by the integrity checker so nothing is
 *                       silently dropped without a paper trail.
 *
 * `option_keys` supports `*` wildcards (leading or trailing, e.g.
 * `wpseo*` or `*_demarkation`), matched against
 * `wp_options.option_name`.
 *
 * `exclude_option_keys` (optional): individual option names/patterns
 * dropped even under include/include_partial -- for transient state,
 * counters, and one-off secrets inside an otherwise-included prefix
 * (e.g. akismet_spam_count under akismet_*).
 *
 * Credential-named options and serialized sub-keys --
 * password|passwd|pwd|secret|key|token|salt|nonce|license|credential
 * matched as whole `_`-separated segments, so "keyword" and
 * "secretary" do NOT match -- still migrate (the destination plugin
 * needs them, e.g. an SMTP password); they are flagged in the
 * migration report so no secret travels invisibly
 * (PluginOptionRule::isSensitiveKeyName).
 *
 * @package MergeMultisite
 */

return array(

	/*
	 * ==================================================================
	 * WHAT GOES HERE (short version of the docblock above)
	 *
	 * Every entry is keyed by PLUGIN SLUG (the plugin's folder name,
	 * e.g. from `wp plugin list`). Two kinds of entry:
	 *
	 *   1. 'mode' => 'include' -- DOCUMENTATION ONLY. Migration is
	 *      default-on, so an include entry does not change behavior;
	 *      it records "this plugin was considered, keep it" and lets
	 *      the report attribute option names to the plugin. Every
	 *      entry in the first sections below is this kind. Yes, each
	 *      one needs its own 'mode' => 'include' line.
	 *
	 *   2. Load-bearing entries -- these CHANGE behavior:
	 *        'exclude'                              plugin's options never migrate
	 *        'include_partial' + 'exclude_subkeys'  copy the serialized value
	 *                                               minus the listed sub-keys
	 *        'exclude_option_keys'                  drop individual options
	 *                                               under an included prefix
	 *        'needs_adapter'                        custom tables; needs a
	 *                                               src/Plugins/ adapter
	 *
	 * Runtime state handled automatically, NO entry needed: Action
	 * Scheduler queue locks/demarkation and _transient_* cache rows
	 * (PluginOptionRule::BUILT_IN_EXCLUDED_OPTION_PATTERNS), plus the
	 * per-install WP options (siteurl, db_version, cron, ...).
	 *
	 * A plugin with DB data but no entry still migrates; the
	 * integrity checker lists it (plugin-data.no-rule) so the
	 * decision stays visible.
	 *
	 * WHERE TO FIND THE DATA FOR THESE DECISIONS
	 *
	 * - Plugin list / exact slugs: `lando wp plugin list` (run inside
	 *   the source site project, ~/sites/wordpress).
	 * - Which options each plugin really has, with paste-ready
	 *   `option_keys` patterns: bin/multisite-integrity-checker.php's
	 *   plugin-data section -- its plugin-data.undecided finding
	 *   prints entries pre-filled from the live DB.
	 * - Which option VALUES differ across sites (your "should this
	 *   migrate / which value wins" review): the divergent-options tab
	 *   of the integrity XLSX (var/reports/integrity-*.xlsx) -- one
	 *   row per diverging option, serialized options exploded into one
	 *   row per sub-key.
	 * - Raw option values, live: `wp option list` /
	 *   `wp option get <name>` in ~/sites/wordpress. On a
	 *   multisite, wp-cli targets the MAIN site by default; add
	 *   `--url=https://<subsite-domain>` for a specific subsite's
	 *   options. `--search` is a LIKE pattern and needs the `*`:
	 *   `wp option list --search=updraft*`.
	 * - Pages actually using a plugin: bin/site-audit.php's reports.
	 * ==================================================================
	 */

	// --- SEO / search: keep (documentation entries) --------------------
	// Wordpress SEO, SEO Framework, Redirection, Relevanssi.
	// 'mode' => 'include' just records the decision. (Option names:
	// integrity checker plugin-data report, or `wp option list
	// --search=wpseo*` etc. on the source.)
	'wordpress-seo'         => array(
'mode' => 'include',
'option_keys' => array( 'wpseo*' ),
	),
	'wordpress-seo-premium' => array(
	'mode' => 'include',
	'option_keys' => array( 'wpseo*' ),
	),
	'autodescription'       => array(
	'mode' => 'include',
	'option_keys' => array( 'autodescription-site-settings' ),
	), // The SEO Framework
	'the-seo-framework-extension-manager' => array(
	'mode' => 'include',
	'option_keys' => array( 'tsfem*' ),
	),
	'redirection'           => array(
	'mode' => 'include',
	'option_keys' => array( 'redirection_options' ),
	),
	'relevanssi'            => array(
	'mode' => 'include',
	'option_keys' => array( 'relevanssi_*' ),
	),

	// --- Forms: keep (documentation entries) ----------------------------
	// Form plugins migrate with their settings so existing forms keep
	// working. (Submitted entries live in CPTs, not options.)
	'wpforms-lite'   => array(
	'mode' => 'include',
	'option_keys' => array( 'wpforms_*' ),
	),
	'ws-form'        => array(
	'mode' => 'include',
	'option_keys' => array( 'ws_form_*' ),
	),
	'ninja-forms'    => array(
	'mode' => 'include',
	'option_keys' => array( 'ninja_forms_*' ),
	),
	'contact-form-7' => array(
	'mode' => 'include',
	'option_keys' => array( 'wpcf7_*' ),
	),
	'formidable'     => array(
	'mode' => 'include',
	'option_keys' => array( 'frm_options' ),
	),
	'sureforms'      => array(
	'mode' => 'include',
	'option_keys' => array( 'srfm_*' ),
	),

	// --- Page builders / theme builders: keep (documentation) -----------
	'elementor'      => array(
	'mode' => 'include',
	'option_keys' => array( 'elementor_*' ),
	),
	'elementor-pro'  => array(
	'mode' => 'include',
	'option_keys' => array( 'elementor_pro_*' ),
	),
	'astra-addon'    => array(
	'mode' => 'include',
	'option_keys' => array( 'astra-addon*' ),
	),
	'ultimate-addons-for-gutenberg' => array(
	'mode' => 'include',
	'option_keys' => array( 'uag_*' ),
	),

	// --- Ecommerce: keep (documentation) ---------------------------------
	'woocommerce'    => array(
	'mode' => 'include',
	'option_keys' => array( 'woocommerce_*' ),
	),
	'surecart'       => array(
	'mode' => 'include',
	'option_keys' => array( 'surecart_*', 'sc_*' ),
	),

	// --- Accessibility / misc site config: keep (documentation) ---------
	'accessibility-checker'         => array(
	'mode' => 'include',
	'option_keys' => array( 'edac_*' ),
	),
	'simple-cloudflare-turnstile'   => array(
	'mode' => 'include',
	'option_keys' => array( 'simple_cf_turnstile_*' ),
	),
	'two-factor'                    => array(
	'mode' => 'include',
	'option_keys' => array(),
	), // user-meta based, no site options to copy

	// --- Security plugins: keep settings, strip junk ---------------------
	// Use 'include_partial' + 'exclude_subkeys' when a serialized
	// option embeds transient data you don't want (e.g. wordfence's
	// IP block logs). Credentials embedded in settings migrate -- do
	// NOT list them here. (Sub-keys to strip: divergent-options tab of
	// integrity-*.xlsx explodes serialized options into one row per
	// sub-key; raw value: `wp option get <name>` on the source.)
	'wordfence' => array(
		'mode' => 'include_partial',
		'option_keys' => array( 'wordfence*' ),
		// These hold IP block lists / login-attempt logs, not settings.
		'exclude_subkeys' => array( 'wordfence_blockedIPLog', 'wordfence_loginLockedOut', 'wordfence_ipTraffic' ),
	),
	// Example entry documenting a plugin whose settings array embeds
	// credentials -- they migrate too (the destination plugin needs
	// e.g. its SMTP password); the migrator flags them in the report.
	'memberwing' => array(
		'mode' => 'include',
		'option_keys' => array( 'MemberWing*', 'memberwing*' ),
	),
	// BBQ (Block Bad Queries) stores its .htaccess-style query
	// patterns in wp_options -- e.g. "bbq_patterns" -- which is why
	// the malware-indicator check may flag "eval(" there. It's the
	// plugin's rule text, not a payload; recognized here so that
	// finding is contextualized instead of alarming.
	'block-bad-queries' => array(
'mode' => 'include',
'option_keys' => array( 'bbq*' ),
	),
	'bbq'               => array(
	'mode' => 'include',
	'option_keys' => array( 'bbq*' ),
	),

	// --- Backup/migration tools: keep (documentation) -------------------
	// Schedules, destinations, and remote-storage settings carry over
	// so backups keep running after the merge; the migration report
	// flags each for destination review (backup name, destination,
	// credentials). (Option names: plugin-data report or `wp option
	// list --search=updraft*` / `--search=wpmdb*` on the source.)
	'updraftplus'      => array(
	'mode' => 'include',
	'option_keys' => array( 'updraft_*' ),
	),
	'wp-migrate-db'    => array(
	'mode' => 'include',
	'option_keys' => array( 'wpmdb_*', 'wp_migrate_db*' ),
	),
	'prime-mover'      => array(
	'mode' => 'include',
	'option_keys' => array( 'prime_mover_*', 'PrimeMover*' ),
	),
	'duplicator-pro'   => array(
	'mode' => 'include',
	'option_keys' => array( 'duplicator_pro*', 'duplicator_pro_*' ),
	),

	// --- Excluded: dev-only / server-specific ----------------------------
	// The only entries that CHANGE behavior: these plugins' options
	// never migrate. Reasons are dev-only, server-specific (reinstall/
	// reconfigure on the destination), or obsolete. Action Scheduler
	// queue state and _transient_* cache rows are handled by the
	// migrator's built-in list
	// (PluginOptionRule::BUILT_IN_EXCLUDED_OPTION_PATTERNS) -- no
	// entry needed here, it applies on every site.
	// (Installed plugins to consider excluding: `wp plugin list`.)
	'query-monitor'    => array(
	'mode' => 'exclude',
	'reason' => 'Dev-only tool, no portable settings.',
	),
	'litespeed-cache'  => array(
	'mode' => 'exclude',
	'reason' => 'Server/cache-specific configuration, not portable between hosts; reported so it can be reinstalled/reconfigured on the destination.',
	),
	'object-cache.php' => array(
	'mode' => 'exclude',
	'reason' => 'Server-specific drop-in, not portable between hosts.',
	),

	// --- Undecided: paste entries here as you decide ----------------------
	// Until they get an entry, undecided plugins still migrate by
	// default and appear in the plugin-data.no-rule report.

	// --- Ignored completely (per project owner's instruction) ------------
	// Never reported, never migrated. 'exclude' + a reason saying so.
	'gl-block-bad-logins'        => array(
	'mode' => 'exclude',
	'reason' => 'Deprecated, owner-authored; ignore entirely.',
	),
	'gl-debug-mode-only-you.php' => array(
	'mode' => 'exclude',
	'reason' => 'Owner-authored debug helper; ignore entirely.',
	),

	// Plugins with custom relational DB tables that a generic options
	// copy cannot handle correctly ('needs_adapter'). Detected by the
	// integrity checker; a dedicated adapter (src/Plugins/) is only
	// built once confirmed in use.
	'pods' => array(
	'mode' => 'needs_adapter',
	'reason' => 'May use custom DB tables/relationships (Advanced Content Types).',
	),
);
