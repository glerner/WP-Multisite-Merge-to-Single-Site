<?php

/**
 * Sample per-plugin `wp_options` migration allowlist.
 *
 * Copy to `option-keys.php` (gitignored, but this sample is safe to
 * keep committed/edited directly since it never contains credentials).
 *
 * Each entry is keyed by plugin slug (as it appears in `wp plugin list`
 * / the plugin's own directory name) and describes which option
 * name(s)/pattern(s) to migrate for it. See PLAN.md §7.5 for the
 * rationale behind each default decision.
 *
 * `mode`:
 *   - 'include'        Option key(s) are copied as-is (through the
 *                       standard SerializedDataRewriter pass).
 *   - 'include_partial' Only specific sub-keys are copied; see
 *                       `exclude_subkeys` for what is stripped out.
 *   - 'exclude'         Plugin is recognized but its data is
 *                       intentionally never migrated (e.g. because it
 *                       holds credentials). Still reported by the
 *                       integrity checker so nothing is silently
 *                       dropped without a paper trail.
 *
 * `option_keys` supports `*` as a trailing wildcard, matched against
 * `wp_options.option_name`.
 *
 * @package MergeMultisite
 */

return array(

	// --- SEO -----------------------------------------------------------
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

	// --- Forms -----------------------------------------------------------
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

	// --- Page builders / theme builders ----------------------------------
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

	// --- Ecommerce ---------------------------------------------------------
	'woocommerce'    => array(
	'mode' => 'include',
	'option_keys' => array( 'woocommerce_*' ),
	),
	'surecart'       => array(
	'mode' => 'include',
	'option_keys' => array( 'surecart_*', 'sc_*' ),
	),

	// --- Accessibility / misc site config ----------------------------------
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

	// --- Security: keep settings, drop transient/security-sensitive data --
	'wordfence' => array(
		'mode' => 'include_partial',
		'option_keys' => array( 'wordfence*' ),
		// These hold IP block lists / login-attempt logs, not settings.
		'exclude_subkeys' => array( 'wordfence_blockedIPLog', 'wordfence_loginLockedOut', 'wordfence_ipTraffic' ),
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

	// --- Excluded: backup/migration tools that carry credentials -----------
	'updraftplus'      => array(
	'mode' => 'exclude',
	'reason' => 'Stores remote-storage (e.g. S3) credentials; reconfigure manually.',
	),
	'wp-migrate-db'    => array(
	'mode' => 'exclude',
	'reason' => 'Stores remote connection credentials; reconfigure manually.',
	),
	'prime-mover'      => array(
	'mode' => 'exclude',
	'reason' => 'Stores remote connection credentials; reconfigure manually.',
	),
	'duplicator-pro'   => array(
	'mode' => 'exclude',
	'reason' => 'Stores remote/storage credentials; reconfigure manually.',
	),

	// --- Excluded: no meaningful/portable DB footprint ----------------------
	'query-monitor'    => array(
	'mode' => 'exclude',
	'reason' => 'Dev-only tool, no portable settings.',
	),
	'litespeed-cache'  => array(
	'mode' => 'exclude',
	'reason' => 'Server/cache-specific configuration, not portable between hosts.',
	),
	'object-cache.php' => array(
	'mode' => 'exclude',
	'reason' => 'Server-specific drop-in, not portable between hosts.',
	),

	// --- Ignored completely (per project owner's instruction) ---------------
	'gl-block-bad-logins'        => array(
	'mode' => 'exclude',
	'reason' => 'Deprecated, owner-authored; ignore entirely.',
	),
	'gl-debug-mode-only-you.php' => array(
	'mode' => 'exclude',
	'reason' => 'Owner-authored debug helper; ignore entirely.',
	),

	// Plugins with custom relational DB tables that a generic options
	// copy cannot handle correctly. Detected by the integrity checker;
	// a dedicated adapter (src/Plugins/) is only built once confirmed
	// in use.
	'pods' => array(
	'mode' => 'needs_adapter',
	'reason' => 'May use custom DB tables/relationships (Advanced Content Types).',
	),
);
