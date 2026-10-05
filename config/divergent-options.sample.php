<?php

/**
 * Sample extra exclusions for the `divergent-site-options` audit
 * check.
 *
 * Copy to `config/divergent-options.php` (gitignored).
 *
 * The check compares autoloaded `wp_options` values across subsites
 * and flags option names whose value differs, because only ONE value
 * can survive the merge. The built-in exclusion list
 * (`DivergentSiteOptionCheck::EXCLUDED_OPTION_NAMES`) already covers
 * WordPress core per-site identity/settings plus transient/cache
 * noise; this file adds YOUR network's known-benign differences --
 * plugin settings that are intentionally per-site, or option names
 * from plugins whose divergence you have already reviewed and
 * accepted.
 *
 * Each entry is a full `wp_options.option_name` string. Wildcards are
 * NOT supported here: for pattern-based noise (transients, cache
 * rows) the built-in rules already handle the common cases, and a
 * per-network exception is almost always a specific named option.
 *
 * To find real candidates on your own network: run the audit, then
 * open the latest report and copy option names verbatim from the
 * divergent-site-options warnings -- `var/reports/site-audit-*.json`
 * (the `groups` context of each warning) or the summary/XLSX.
 *
 * @package MergeMultisite
 */

return array(
	// Real-world example: Yoast SEO's social-profiles setting holds
	// per-site handles/URLs that legitimately differ across subsites
	// (and the merged site reconfigures them anyway), so the check
	// should stop flagging it as a merge conflict:
	'wpseo_social',

	// 'my-plugin-setting',
);
