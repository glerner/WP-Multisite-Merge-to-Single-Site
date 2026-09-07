<?php

/**
 * Sample site-selection configuration.
 *
 * Copy to `sites.php` (gitignored). Every entry is one subsite found in
 * `wp_blogs`/`wp_site`. Run
 * `php bin/multisite-integrity-checker.php --list-sites` against your
 * real source database to get a starting list of every non-deleted
 * site to copy in here.
 *
 * Any site NOT listed here defaults to `include = true` as long as it
 * is not marked deleted in the source database -- so you only need to
 * list the sites you want to exclude or override.
 *
 * @package MergeMultisite
 */

return array(
	array(
		'blog_id'       => 1,
		'domain'        => 'www.example.com',
		'include'       => true,
		// Optional overrides -- omit to default to the site's own title/slug.
		'category_name' => 'Main Site',
		'category_slug' => 'main-site',
	),
	array(
		'blog_id'       => 7,
		'domain'        => 'website-tech.example.com',
		'include'       => true,
		'category_name' => 'Website Tech',
		'category_slug' => 'website-tech',
	),
	array(
		// Example of a site excluded from the merge (it will remain its
		// own standalone single site instead).
		'blog_id' => 12,
		'domain'  => 'molten-salt-reactor.example.com',
		'include' => false,
	),
);
