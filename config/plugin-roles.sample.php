<?php

/**
 * Plugin role/signal overrides for the site-audit "Plugin role
 * conflicts" and "Plugin usage" report sections.
 *
 * Copy to config/plugin-roles.php (gitignored).
 *
 * Everything here AUGMENTS the built-in lists in PluginUsageRollup:
 * add a new family, append slugs to an existing family, or teach the audit
 * a new content signal -- nothing needs to be copied here just to keep
 * working; prefix a family slug with '-' to remove a built-in.
 *
 * Five optional sections:
 *
 * 1. 'conflict_families'
 *   family-label => list of installed-plugin directory slugs. A family is
 *   reported when 2+ of its members are installed and active; sites where
 *   they are co-active are flagged as real conflicts.
 *
 *   The 10 built-in conflict families in the main program are:
 *     - 'Mail delivery / SMTP'
 *     - 'Contact forms'
 *     - 'SEO'
 *     - 'Comment spam'
 *     - 'Caching/performance'
 *     - 'Image optimization'
 *     - 'CDN/edge'
 *     - 'Security'
 *     - 'Backups'
 *     - 'Page builders'
 *
 *   Use the exact label to add a plugin slug to a built-in family, or
 *   prefix a slug with '-' to remove it:
 *     'Contact forms' => array( 'my-custom-forms' ),
 *     'Page builders' => array( '-themify-builder' ),
 *
 * 2. 'signal_map'
 *   detector-token => array( 'name' => display-name, 'slugs' => array( candidate directory slugs ) ).
 *   Maps block prefixes, shortcodes, and postmeta tokens to known plugins.
 *
 * 3. 'not_a_plugin'
 *   tokens that look like signals but are platform/core output, not a plugin.
 *   Affects only the "Plugin usage" rollup, NOT the block-inventory XLSX
 *   tab -- the inventory is a complete census; filter its Block column
 *   in the spreadsheet to hide a known namespace's rows.
 *
 * 4. 'detector_extras'
 *   Augments signature tables for table-driven detectors ('form_plugin',
 *   'seo_plugin', 'gallery', 'shortcodes').
 *
 * 5. 'suppressions'
 *   Plugin-specific finding suppressions (same rule shape as config.php
 *   "suppressions", plus a 'check-name' => slug[] shorthand) -- keeps all
 *   plugin configuration in one file; general, non-plugin rules stay in
 *   config.php. Both are merged.
 *
 *   HOW TO CONVERT SPREADSHEET BLOCKS TO CONTENT SIGNATURES:
 *     In the site-audit spreadsheet, the "blocks" column displays block identifiers
 *     such as "wsf-block/form-add" or "uagb/forms".
 *
 *     In raw WordPress database content (wp_posts.post_content), Gutenberg blocks
 *     are stored as HTML comments prefixed by "wp:":
 *       <!-- wp:wsf-block/form-add {"id": 1} -->
 *       <!-- wp:uagb/forms {"id": 2} -->
 *
 *     To match these in 'content_signatures':
 *       - Always prefix with 'wp:'.
 *       - What appears before the '/' is the Block Namespace (e.g. 'wsf-block', 'uagb').
 *       - Matching just the namespace prefix (e.g. 'wp:wsf-block' or 'wp:uagb\/forms\b')
 *         will match any block in that namespace or specific form block.
 *
 *   HOW TO CHECK YOUR CONFIGURATION & CATCH SYNTAX ERRORS:
 *     - Check PHP syntax: run `php -l config/plugin-roles.php` in terminal.
 *     - Check detection: run `php bin/site-audit.php --site=<blog_id>` on a site
 *       with the block; confirm the Form Plugin column now shows your label.
 *
 * @package MergeMultisite
 */

return array(
	'conflict_families' => array(
		// Example: Add a new form plugin to the built-in 'Contact forms' family:
		// 'Contact forms' => array( 'ws-form' ),

		// Example: Create a new custom conflict family:
		// 'Membership' => array( 'memberpress', 'paid-member-subscriptions', 'restrict-content' ),

		// Example: Remove a plugin slug from a built-in family:
		// 'Page builders' => array( '-themify-builder' ),
	),

	'signal_map'        => array(
		// Maps block prefixes to plugin display names and directory slugs:
		'wsf'  => array(
			'name'  => 'WS Form',
			'slugs' => array( 'ws-form' ),
		),
		'uagb' => array(
			'name'  => 'Spectra (Ultimate Addons for Gutenberg)',
			'slugs' => array( 'ultimate-addons-for-gutenberg' ),
		),
	),

	'not_a_plugin'      => array(
		// Tokens that look like plugin signals but are platform/core
		// output, never a plugin. Matching is by condensed prefix and
		// longest match wins, so keep tokens specific. Working example:
		// core WordPress shortcodes ([audio], [video], [playlist]) the
		// audit would otherwise list as unknown signals:
		// 'audio',
		// 'video',
		// 'playlist',
		// Block example: Jetpack blocks in content while Jetpack is NOT
		// installed (e.g. imported posts) surface as an unknown signal:
		// 'jetpack',
		// Does not apply to the block-inventory XLSX tab -- filter its
		// "Block (Namespace/Name)" column there instead.
	),

	'suppressions'      => array(
		// Plugin-specific finding suppressions live HERE (all plugin
		// configuration in one file); general rules stay in config.php.
		// Both are merged. 'plugin' is the plugin's directory slug --
		// the folder name in wp-content/plugins, as shown by
		// `wp plugin list`.
		//
		// Shorthand (when every rule shares one check name):
		// 'check-name' => array( 'slug1', 'slug2' ) expands to one rule
		// per slug with context 'plugin' => slug:
		'plugin-data.no-rule' => array(
			// 'my-plugin',
		),
		// Long form (any context keys, same shape as config.php):
		// array( 'check' => 'media-files.missing-file', 'blog_id' => 26 ),
	),

	'detector_extras'   => array(
		'form_plugin' => array(
			'content_signatures' => array(
				// Matches <!-- wp:wsf-block/form-add --> and all WS Form blocks:
				'WS Form'      => array( 'wp:wsf-block' ),
				// Matches <!-- wp:uagb/forms --> Spectra Form blocks:
				'Spectra Form' => array( 'wp:uagb\/forms\b', 'uagb/forms' ),
			),
		),
	),
);
