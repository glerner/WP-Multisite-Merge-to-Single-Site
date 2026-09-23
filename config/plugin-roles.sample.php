<?php

/**
 * Plugin role/signal overrides for the site-audit "Plugin role
 * conflicts" and "Plugin usage" report sections.
 *
 * Copy to config/plugin-roles.php (gitignored).
 *
 * Everything here AUGMENTS the built-in lists in
 * PluginUsageRollup: add a new family, append slugs to an existing
 * family, or teach the audit a new content signal -- nothing needs
 * to be copied here just to keep working; prefix a family slug
 * with '-' to remove a built-in.
 *
 * Three optional keys:
 *
 * 'conflict_families'
 *   family-label => installed-plugin directory slugs. A family is
 *   reported when 2+ of its members are installed and active; sites
 *   where they are co-active are flagged as real conflicts. Use the
 *   exact same label to add slugs to a built-in family ('SEO',
 *   'Contact forms', 'Comment spam', 'Mail delivery', 'Analytics',
 *   'Page builders', ...) or a new label to create a category.
 *   Prefix a slug with '-' to remove it from a built-in family.
 *
 * 'signal_map'
 *   detector-token => array( 'name' => display-name,
 *   'slugs' => candidate plugin directory names ). The token is
 *   matched as a case-insensitive prefix/substring against detector
 *   labels (meta prefixes, block names, post types, shortcodes); the
 *   candidates are checked against installed plugins so the signal
 *   resolves to the right plugin name.
 *
 * 'not_a_plugin'
 *   tokens that look like signals but are platform output, not a
 *   plugin (e.g. core editor blocks) -- they are never reported as
 *   "signal has no matching plugin".
 *
 * 'detector_extras'
 *   detector-category => signature-table additions for the
 *   table-driven detectors, so a newly-seen plugin signature is a
 *   config edit rather than a source edit. The key is the detector's
 *   category() string; only 'seo_plugin', 'form_plugin', 'gallery',
 *   and 'shortcodes' take extras (the other detectors are hardcoded
 *   logic, not tables). Extras augment the built-ins; they never
 *   remove one.
 *
 *   'seo_plugin' => array(
 *       // meta-key prefix => plugin label; a known prefix's label
 *       // is replaced, a new prefix is added.
 *       'meta_prefixes' => array( '_myseo_' => 'My SEO Plugin' ),
 *   ),
 *
 *   'form_plugin' => array(
 *       // label => regex patterns matched against post_content;
 *       // patterns APPEND to a known label, a new label is added.
 *       'content_signatures' => array( 'My Form' => array( '\[myform\b', 'wp:myform\/' ) ),
 *       // label => array( meta_key, needle searched inside its value );
 *       // a known label's tuple is REPLACED.
 *       'meta_signatures'    => array( 'My Form' => array( '_myform_data', '"type":"form"' ) ),
 *       // label => patterns matched against a raw <form> action URL.
 *       'action_signatures'  => array( 'My Processor' => array( 'myprocessor' ) ),
 *   ),
 *
 *   'gallery' => array(
 *       'content_signatures'         => array( 'My Gallery' => array( '\[mygallery\b' ) ),
 *       'meta_signatures'            => array( 'My Gallery' => array( '_my_gallery', 'needle' ) ),
 *       // For PHP-serialized builder layouts: meta_key + module type.
 *       'serialized_meta_signatures' => array( 'My Builder' => array( '_my_builder_data', 'gallery' ) ),
 *   ),
 *
 *   'shortcodes' => array(
 *       // Extra never-real shortcode tags, on top of IGNORED_TAGS and
 *       // shortcode-ignore.php.
 *       'ignored_tags' => array( 'mytag' ),
 *   ),
 *
 * @package MergeMultisite
 */

return array(
	'conflict_families' => array(
		// Append a member to a built-in family:
		// 'Contact forms' => array( 'my-form-plugin' ),

		// Or create a whole new family:
		// 'Membership' => array( 'memberpress', 'paid-member-subscriptions', 'restrict-content' ),

		// Or remove a slug from a built-in family:
		// 'Page builders' => array( '-themify-builder' ),
	),
	'signal_map'        => array(
		// 'myform' => array( 'name' => 'My Form Plugin', 'slugs' => array( 'my-form-plugin' ) ),
	),
	'not_a_plugin'      => array(
		// 'core-image-block',
	),
	'detector_extras'   => array(
		// 'seo_plugin' => array(
		// 'meta_prefixes' => array( '_myseo_' => 'My SEO Plugin' ),
		// ),
		// 'form_plugin' => array(
		// 'content_signatures' => array( 'My Form' => array( '\[myform\b' ) ),
		// ),
	),
);
