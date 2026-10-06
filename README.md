# Merge Multisite

Standalone PHP CLI tools to audit, inventory, and merge a WordPress
multisite network into a single WordPress site. See [`PLAN.md`](PLAN.md)
for the full design and rationale.

The destination must be a standard single-site WordPress installation;
multisite-to-multisite merging is not supported. All destination schema
operations and content migrations target single-site tables (`wp_users`,
`wp_posts`, `wp_comments`, `wp_options`).

This is **not** a WordPress plugin. It has no admin UI and does not run
inside WordPress at all -- it's a set of Composer/PSR-4 autoloaded PHP
CLI scripts that connect directly (via PDO) to your source multisite's
database and your destination single-site's database.

## Requirements

- PHP 8.2+
- Composer
- Read access to the source multisite's database and
  `wp-content` directory (uploads for media checks, `themes/` for
  template detection)
- (Optional, for template screenshots) `shot-scraper` -- see
  `docs/making-screenshots.md`
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
assumed to be `wp_` -- it's always read from config).

Edit `config/sites.php` to exclude any subsites you don't want merged.

`config.php`, `sites.php`, `option-keys.php`, and `term-overrides.php`
are gitignored since they contain real credentials/site data; only the
`*.sample.php` versions are committed.

### Creating the Destination WordPress Site (WP-CLI)

The destination must be a clean, standard single-site WordPress installation. For detailed environment guides covering **Lando**, **Local by Flywheel**, and **WordPress Studio**, see [`docs/local-environments.md`](docs/local-environments.md).

The standard way to create it via WP-CLI:

```bash
# 1. Download WordPress core into your destination directory
# If you have all your sites in ~/sites/, you might have WordPress source in ~/sites/wp-multisite/ and the destination in ~/sites/wp-destination/
wp core download --path=/path/to/destination

# 2. Create wp-config.php
# Substitute your values for dbname, dbuser, dbpass.
# Use the value your local development expects for dbhost
# Use the same path for the destination as the previous step
wp config create \
  --dbname=destination_db \
  --dbuser=db_user \
  --dbpass=db_password \
  --dbhost=localhost \
  --path=/path/to/destination

# 3. Create the database (if not already created by your local development environment)
wp db create --path=/path/to/destination

# 4. Install WordPress as a single site with an initial admin user
# Substitute the value you will use for the URL of your production site
# Alternate procedure: enter the URL For your local development, test everything works, and rerun this migration with a new database with the URL of the production site
# Use your choice of other values, except the same destination path
wp core install \
  --url="https://destination.example.com" \
  --title="Merged Site" \
  --admin_user="initial_admin" \
  --admin_password="choose-a-strong-password" \
  --admin_email="admin@example.com" \
  --path=/path/to/destination

# 5. Renumber the initial admin user ID (prevents ID 1 collision)
php bin/harden-admin-id.php
```

After running `harden-admin-id.php`, set `'admin_user_id'` in `config/config.php` to the assigned ID.

## Tools

### `bin/test-connections.php`

Pre-flight connection verification: connects to both the source multisite and destination single-site databases simultaneously within a single PHP process, verifies that table prefixes match real tables, checks user and site counts, validates that `admin_user_id` in `config.php` matches a real destination user, and tests readability/writeability of the uploads directories.

```bash
php bin/test-connections.php
```

**Connection refused?** `SQLSTATE[HY000] [2002]` / `[2003]` means the
host and port answered but no MySQL server is listening — your settings
are correct; the database server simply isn't running. Start the site's
environment first (`lando start` in that site's own folder, start the
site in Local/Studio, `docker compose up -d`, or
`sudo systemctl start mysql`), then re-run. The error output prints the
same hints plus the config-review steps for other failures.

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

`--list-sites` prints every subsite as paste-ready `sites.php` entries
(one `array( ... )` per site, with `include` and `category_name` -- the
site's title -- already filled in), so you can drop them straight into
`config/sites.php`. Deleted sites are listed too, marked
`'include' => false, 'deleted' => true` -- `deleted` is informational
only (`SiteConfig` ignores it); review those lines and correct any
site that shouldn't be deleted. To generate the complete
file instead, redirect the PHP variant:

```bash
php bin/multisite-integrity-checker.php --list-sites-php > config/sites.php
```

Then edit `config/sites.php`: flip `'include' => true` to
`'include' => false` for any subsite you want to leave out of the merge,
and adjust `'category_name'` (defaults to the site's title) if you want
a shorter destination category name. That name becomes the WordPress
category that site's content is merged under, so keep it to 1-3 words;
the full site title is preserved automatically in the category's
description, and `'category_slug'` is derived from the name unless you
set it. Re-running `--list-sites-php` is safe -- it keeps the
include/category overrides already in the file.

Reports are written to `var/reports/integrity-*.md`, and to `var/reports/integrity-*.json` (same information, 2 formats). When the divergent-site-options section is truncated (more than 50 divergent options), the complete uncapped list — every option, every distinct value, and the sites holding each — is written to a `divergent-options` tab in `var/reports/integrity-*.xlsx` (or a same-named `.csv` when ext-zip is missing), so you can sort/filter the whole list in a spreadsheet.

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
php bin/site-audit.php            # bare invocation = --all-sites
php bin/site-audit.php --all-sites
php bin/site-audit.php --post-types=post,page
```

Running bare is equivalent to `--all-sites` (every non-deleted,
included site); use `--site=<blog_id>` to scan a single subsite.

Each scanned page also gets `template` / `template_status` columns: which
template it renders through (resolved via `_wp_page_template` or the
block-theme hierarchy, including child→parent inheritance), and whether
that template is stock, `customized`, a `stale-customization` (a dormant
row owned by an inactive theme -- retagging it to the active stylesheet
restores it), a `missing-template`, `plugin-template`, `classic-theme`
(the site runs a PHP-templated classic theme, so per-page block-template
detail doesn't apply), etc. Stale template options are flagged too: when
a theme's `Template:` style.css header disagrees with the `template`
option (e.g. after a manual theme switch), the header wins and a warning
is logged.

Output goes to `var/reports/site-audit-*.csv`, `var/reports/site-audit-*.json`, and `var/reports/site-audit-*.xlsx` (xlsx when
ext-zip is available)

Also output is a `-summary.md` tally, e.g. "12 pages use
Contact Form 7, 3 use WPForms", with a "Page templates needing work"
section listing stale/customized/missing templates by site.

A `site-audit-needs-review-*.csv` lists the pages likely needing manual
post-merge edits.

### Output to Spreadsheet format .xlsx

In the `.xlsx` workbook, a second `block-inventory` tab lists every
(non-core) block usage -- one row per block found on a page -- with
owning plugin, occurrence total, and the page's URL, so filtering the
"Used On URL" column shows every block on a page and filtering the
block column shows every page (URL) that uses a given block.
A third `needs-review` tab carries the same rows as the `site-audit-needs-review` CSV (original + guessed destination URL, detected plugins, raw data dumps).
This workbook opens in any spreadsheet app that reads the Open XML format:
Google Sheets (cloud), and cross-platform apps (Windows, OS/X, Linux)
Microsoft Excel, LibreOffice / OpenOffice Calc (free), WPS Office; and
Apple Numbers (OS/X). On a very large network the block tab can run to
thousands of rows -- pass `--site=<blog_id>` to audit one subsite at a
time when that's all you need.

The `.xlsx` requires PHP's `zip` extension (`php -m | grep zip` to
check). If it's missing: Debian/Ubuntu `sudo apt install php-zip` (or
`php8.x-zip` matching your PHP version), Homebrew PHP on macOS ships it
enabled, and on Windows uncomment `extension=zip` in `php.ini`. The CSV
is written regardless, so a missing extension degrades gracefully
instead of failing.

### `bin/template-screenshots.php`

Read-only visual inventory: screenshots of each included site's templates
and template parts (header/footer/sidebar) with shot-scraper, so theme
output can be compared across sites before the merge. Requires
`shot-scraper` (see `docs/making-screenshots.md`).

Each template is shot on a page that actually renders it -- resolved via
`show_on_front`/`page_on_front`/`page_for_posts`, WooCommerce page-ID
options, or a sample published post -- and each part on a page whose
template includes it (parsed from `wp:template-part` refs in DB rows and
theme files). Templates with no reachable rendering page are skipped with
a reason; parts without a reliable CSS selector are covered by the
full-page shots of the templates that include them. Rows customized under
an inactive theme are reported as retag candidates, not screenshotted.

```bash
php bin/template-screenshots.php            # all included sites
php bin/template-screenshots.php --site=20  # one site
php bin/template-screenshots.php --dry-run  # write shots.yml plan only
```

Output goes to `var/reports/template-shots/{blogId}-{slug}.png` with the
plan in `shots.yml`.

#### Found a block/shortcode/plugin this doesn't detect yet?

The detectors in `src/ContentAudit/Detectors/` are a best-effort
starting list, not an exhaustive survey of every WordPress plugin/page
builder combination. That's by design: rather than guessing every
possible signature up front, running `site-audit.php` against real
data (and checking `ShortcodeDetector`'s raw shortcode list, which
catches anything the more specific detectors don't recognize yet) is
meant to surface what's actually out there, so the specific detectors
can be refined from evidence rather than guesswork.

You don't need to wait for a code change to teach the audit a new
signal -- `config/plugin-roles.php` (see
`config/plugin-roles.sample.php`) is read at runtime:

- `signal_map` — map a block namespace or shortcode to a plugin's
  display name and directory slug (e.g. `'wsf' => array('name' =>
  'WS Form', 'slugs' => array('ws-form'))`).
- `detector_extras` — add signature-table entries for the
  table-driven detectors (`form_plugin`, `seo_plugin`, `gallery`,
  `shortcodes`), matching raw content signatures like
  `wp:uagb\/forms\b`.

If a signature is genuinely new (not just missing from your config),
please also report it at
**[glerner.com/contact](https://glerner.com/contact)** so it can be
added to the built-in lists for everyone.

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

### `bin/migrate.php` (Phases 3–4 implemented)

The migration itself. Runs the implemented phases in dependency order
(PLAN.md §5): **Users** → **Terms** → **Media** (files + attachment
posts). Phases 5–8 (posts, comments, menus/widgets, URL rewriting) are
not built yet and will be appended here as they land.

```bash
php bin/migrate.php --dry-run                      # plan the whole run
php bin/migrate.php --move-media-only --dry-run    # plan media only
php bin/migrate.php --move-media-only              # media first (PLAN.md §7.2)
php bin/migrate.php                                # real run, all built phases
```

- `--dry-run` — resolves every destination path and ID, logs every
  planned write and file copy, but writes nothing to the destination
  database and copies no files. The JSON run report
  (`var/reports/migrate-*-dryrun.json`) shows exactly what a real run
  would do, so review it first.
- `--move-media-only` — runs just the media phase: copies files with
  size + SHA-256 dedup (identical files collapse to one physical file,
  and files already on the destination are reused), renames same-name
  different-content collisions to `{basename}_site{blog_id}.{ext}`
  (thumbnail sizes keep `-{W}x{H}` last, e.g. `logo_site7-150x150.png`),
  and recreates `attachment` posts with `_wp_attached_file` and
  `_wp_attachment_metadata` rewritten to the destination paths.
- `--site=<blog_id>` — restrict to one subsite; handy for very large
  networks (run one site at a time).
- **Idempotent / resumable** — every migrated row's origin is recorded
  in the destination's `{prefix}merge_migration_map` table, so
  re-running (or resuming after an interruption) skips already-migrated
  rows instead of double-inserting. No separate `--resume` flag.
- Run reports land in `var/reports/migrate-*.json`, logs in
  `var/logs/migrate-*.log`, and the in-memory ID map is snapshotted to
  `var/state/idmap-*.json` after a real run.

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
