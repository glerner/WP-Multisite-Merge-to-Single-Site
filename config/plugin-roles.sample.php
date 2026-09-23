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
);
