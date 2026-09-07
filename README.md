# Merge Multisite

Standalone PHP CLI tools to audit, inventory, and merge a WordPress
multisite network into a single WordPress site. See [`PLAN.md`](PLAN.md)
for the full design and rationale.

This is **not** a WordPress plugin. It has no admin UI and does not run
inside WordPress at all -- it's a set of Composer/PSR-4 autoloaded PHP
CLI scripts that connect directly (via PDO) to your source multisite's
database and your destination single-site's database.

## Requirements

- PHP 8.2+
- Composer
- Read access to the source multisite's database and
  `wp-content/uploads` directory
- (Once the migrator is used) write access to a destination
  WordPress database and `wp-content/uploads` directory

## Setup

```bash
composer install
cp config/config.sample.php config/config.php
cp config/sites.sample.php config/sites.php
cp config/option-keys.sample.php config/option-keys.php
```

Edit `config/config.php` with your source/destination database
credentials and table prefixes (the network's table prefix is never
assumed to be `wp_` -- it's always read from config). Edit
`config/sites.php` to exclude any subsites you don't want merged.

`config.php`, `sites.php`, `option-keys.php`, and `term-overrides.php`
are gitignored since they contain real credentials/site data; only the
`*.sample.php` versions are committed.

## Tools

### `bin/multisite-integrity-checker.php`

Read-only pre-flight audit of the source multisite: orphaned post
authors/parents, missing or colliding media files, user login/email
conflicts, category/tag case-collision preview, plugin data with no
configured migration rule, and more. Run this first, before ever
touching the migrator.

```bash
php bin/multisite-integrity-checker.php --list-sites
php bin/multisite-integrity-checker.php
php bin/multisite-integrity-checker.php --strict
```

Reports are written to `var/reports/integrity-*.{md,json}`.

### `bin/site-audit.php`

Read-only content/plugin-usage inventory: scans every post/page (one
subsite, or a whole multisite) for non-core Gutenberg blocks, raw
shortcodes, page-builder usage, contact-form plugins, galleries, video
embeds, SEO plugin, and ecommerce plugins -- so you can see exactly
which pages use which duplicate plugins (e.g. three different contact
form plugins across three subsites) before deciding what to
standardize on.

```bash
php bin/site-audit.php --site=7
php bin/site-audit.php --all-sites
php bin/site-audit.php --all-sites --post-types=post,page
```

Output goes to `var/reports/site-audit-*.{csv,json}` plus a
`-summary.md` tally, e.g. "12 pages use Contact Form 7, 3 use WPForms".

#### Found a block/shortcode/plugin this doesn't detect yet?

The detectors in `src/ContentAudit/Detectors/` are a best-effort
starting list, not an exhaustive survey of every WordPress plugin/page
builder combination. That's by design: rather than guessing every
possible signature up front, running `site-audit.php` against real
data (and checking `ShortcodeDetector`'s raw shortcode list, which
catches anything the more specific detectors don't recognize yet) is
meant to surface what's actually out there, so the specific detectors
can be refined from evidence rather than guesswork.

If you run this against your own multisite and find a block, shortcode,
or plugin signature that isn't recognized, please report it at
**[glerner.com/contact](https://glerner.com/contact)** so it can be
added.

### `bin/harden-admin-id.php`

Renumbers the destination site's admin user away from ID 1 -- defense
in depth against username-enumeration scripts that probe
`?author=1` (WordPress's default author-archive redirect reveals that
user's username to anyone; this does **not** relate to SQL injection,
which parameterized queries -- used throughout this project -- already
prevent regardless of a user's ID). Run this once, right after creating
the destination site, before setting `admin_user_id` in `config.php`
or running `migrate.php` against that destination.

```bash
php bin/harden-admin-id.php --dry-run
php bin/harden-admin-id.php
php bin/harden-admin-id.php --new-id=42
```

### `bin/migrate.php` (in progress)

The actual migration. See `PLAN.md` for the full design (ID remapping,
media dedup, term merging, URL rewriting, redirect maps, etc.). Not yet
implemented.

## Development

```bash
composer test          # PHPUnit
composer phpcs         # Coding-standards check
composer phpcbf        # Auto-fix what's fixable
composer analyze       # PHPStan
```

This project's `phpcs.xml` intentionally does not enforce full
WordPress Coding Standards (no forced snake_case, no Yoda conditions,
no per-parameter prose docblocks) -- see `phpcs.xml`'s comments and
`PLAN.md` §3/§3.1 for why. If your editor's linter shows different
warnings than `composer phpcs` does, see
[`docs/editor-coding-standards.md`](docs/editor-coding-standards.md).

## License

GPL-2.0-or-later.
