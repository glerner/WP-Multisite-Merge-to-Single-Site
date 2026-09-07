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

return [

    // --- SEO -----------------------------------------------------------
    'wordpress-seo'         => ['mode' => 'include', 'option_keys' => ['wpseo*']],
    'wordpress-seo-premium' => ['mode' => 'include', 'option_keys' => ['wpseo*']],
    'autodescription'       => ['mode' => 'include', 'option_keys' => ['autodescription-site-settings']], // The SEO Framework
    'the-seo-framework-extension-manager' => ['mode' => 'include', 'option_keys' => ['tsfem*']],
    'redirection'           => ['mode' => 'include', 'option_keys' => ['redirection_options']],
    'relevanssi'            => ['mode' => 'include', 'option_keys' => ['relevanssi_*']],

    // --- Forms -----------------------------------------------------------
    'wpforms-lite'   => ['mode' => 'include', 'option_keys' => ['wpforms_*']],
    'ws-form'        => ['mode' => 'include', 'option_keys' => ['ws_form_*']],
    'ninja-forms'    => ['mode' => 'include', 'option_keys' => ['ninja_forms_*']],
    'contact-form-7' => ['mode' => 'include', 'option_keys' => ['wpcf7_*']],
    'formidable'     => ['mode' => 'include', 'option_keys' => ['frm_options']],
    'sureforms'      => ['mode' => 'include', 'option_keys' => ['srfm_*']],

    // --- Page builders / theme builders ----------------------------------
    'elementor'      => ['mode' => 'include', 'option_keys' => ['elementor_*']],
    'elementor-pro'  => ['mode' => 'include', 'option_keys' => ['elementor_pro_*']],
    'astra-addon'    => ['mode' => 'include', 'option_keys' => ['astra-addon*']],
    'ultimate-addons-for-gutenberg' => ['mode' => 'include', 'option_keys' => ['uag_*']],

    // --- Ecommerce ---------------------------------------------------------
    'woocommerce'    => ['mode' => 'include', 'option_keys' => ['woocommerce_*']],
    'surecart'       => ['mode' => 'include', 'option_keys' => ['surecart_*', 'sc_*']],

    // --- Accessibility / misc site config ----------------------------------
    'accessibility-checker'         => ['mode' => 'include', 'option_keys' => ['edac_*']],
    'simple-cloudflare-turnstile'   => ['mode' => 'include', 'option_keys' => ['simple_cf_turnstile_*']],
    'two-factor'                    => ['mode' => 'include', 'option_keys' => []], // user-meta based, no site options to copy

    // --- Security: keep settings, drop transient/security-sensitive data --
    'wordfence' => [
        'mode' => 'include_partial',
        'option_keys' => ['wordfence*'],
        // These hold IP block lists / login-attempt logs, not settings.
        'exclude_subkeys' => ['wordfence_blockedIPLog', 'wordfence_loginLockedOut', 'wordfence_ipTraffic'],
    ],
    // BBQ (Block Bad Queries) stores its .htaccess-style query
    // patterns in wp_options -- e.g. "bbq_patterns" -- which is why
    // the malware-indicator check may flag "eval(" there. It's the
    // plugin's rule text, not a payload; recognized here so that
    // finding is contextualized instead of alarming.
    'block-bad-queries' => ['mode' => 'include', 'option_keys' => ['bbq*']],
    'bbq'               => ['mode' => 'include', 'option_keys' => ['bbq*']],

    // --- Excluded: backup/migration tools that carry credentials -----------
    'updraftplus'      => ['mode' => 'exclude', 'reason' => 'Stores remote-storage (e.g. S3) credentials; reconfigure manually.'],
    'wp-migrate-db'    => ['mode' => 'exclude', 'reason' => 'Stores remote connection credentials; reconfigure manually.'],
    'prime-mover'      => ['mode' => 'exclude', 'reason' => 'Stores remote connection credentials; reconfigure manually.'],
    'duplicator-pro'   => ['mode' => 'exclude', 'reason' => 'Stores remote/storage credentials; reconfigure manually.'],

    // --- Excluded: no meaningful/portable DB footprint ----------------------
    'query-monitor'    => ['mode' => 'exclude', 'reason' => 'Dev-only tool, no portable settings.'],
    'litespeed-cache'  => ['mode' => 'exclude', 'reason' => 'Server/cache-specific configuration, not portable between hosts.'],
    'object-cache.php' => ['mode' => 'exclude', 'reason' => 'Server-specific drop-in, not portable between hosts.'],

    // --- Ignored completely (per project owner's instruction) ---------------
    'gl-block-bad-logins'        => ['mode' => 'exclude', 'reason' => 'Deprecated, owner-authored; ignore entirely.'],
    'gl-debug-mode-only-you.php' => ['mode' => 'exclude', 'reason' => 'Owner-authored debug helper; ignore entirely.'],

    // Plugins with custom relational DB tables that a generic options
    // copy cannot handle correctly. Detected by the integrity checker;
    // a dedicated adapter (src/Plugins/) is only built once confirmed
    // in use.
    'pods' => ['mode' => 'needs_adapter', 'reason' => 'May use custom DB tables/relationships (Advanced Content Types).'],
];
