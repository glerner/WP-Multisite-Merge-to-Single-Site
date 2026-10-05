<?php

/**
 * Sample site-selection configuration.
 *
 * Copy to `sites.php` (gitignored). Every entry is one subsite found in
 * `wp_blogs`/`wp_site`. Run
 * `php bin/multisite-integrity-checker.php --list-sites` against your
 * real source database to get a starting list of every site to copy in
 * here (deleted sites are emitted as 'include' => false plus a
 * 'deleted' => true marker -- informational only, ignored on load).
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
		// 'category_name' is the destination category the site's content is
		// merged under; keep it short (1-3 words) -- the full site title is
		// preserved automatically in the category description.
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
		// Example of a site excluded from the merge (it will extract
		// to its own standalone single site instead).
		'blog_id' => 12,
		'domain'  => 'molten-salt-reactor.example.com',
		'include' => false,
	),
);
