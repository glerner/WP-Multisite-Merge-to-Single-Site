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
 * Four optional sections:
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
 *
 * 4. 'detector_extras'
 *   Augments signature tables for table-driven detectors ('form_plugin',
 *   'seo_plugin', 'gallery', 'shortcodes').
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
		// Tokens that represent core editor output rather than plugins:
		// 'core-image-block',
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
