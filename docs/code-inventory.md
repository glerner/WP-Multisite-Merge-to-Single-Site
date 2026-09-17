# merge-multisite — Code Inventory

Lookup reference for files, classes, and design decisions. For the phased plan
see `PLAN.md`; for coding standards see `docs/editor-coding-standards.md`.

## Overview

Standalone PHP CLI tooling (no WordPress runtime dependency) to audit a
WordPress multisite network and migrate it into a single-site WordPress
install. PHP 8.2+, Composer autoload `MergeMultisite\` → `src/`, tests under
`tests/Unit/` (PHPUnit 11), code style via `phpcs.xml` (WordPress standard +
Slevomat), static analysis via `phpstan.neon` (PHPStan 2.x).

Verify everything with: `composer test && composer phpcs && composer phpstan`.

## Entry points (`bin/`)

| File | Purpose |
|---|---|
| `bin/site-audit.php` | Content/plugin-usage audit: scans posts across included sites, reports blocks/shortcodes/plugin footprints per page. Options: `--site=<id>`, `--all-sites`, `--post-types=…`, `--search=…`, `--config=<dir>`. Writes CSV + JSON + summary.md + XLSX to `var/reports/`, plus a needs-review CSV. |
| `bin/multisite-integrity-checker.php` | Read-only pre-flight audit: runs every `AuditCheckInterface` check. `--strict`, `--list-sites`, `--config=`. Writes Markdown + JSON to `var/reports/` and a media-recovery script to `var/`. |
| `bin/harden-admin-id.php` | Renumbers destination admin user away from ID 1 (run once, before migration; see `AdminIdRenumberer`). |
| `bin/run-wpscan.php` | Optional wrapper around external `wpscan` CLI (Ruby gem) for vulnerability checks against a live URL. |

## Configuration (`config/`)

`ConfigLoader` reads `config.php` (required) plus optional
`sites.php`, `option-keys.php`, `term-overrides.php`, `shortcode-ignore.php`
and produces one `MergeConfig`. All real config files are gitignored; each has
a `*.sample.php` committed.

- `config.php` — DB endpoints, `destination_url`, `excluded_post_types`,
  `excluded_post_statuses`, `term_merge_rule`, `contact_page_paths`,
  `suppressions` (finding-suppression rules), `media_search_paths`,
  `wpscan_api_token`.
- `sites.php` — per-site `include` bool + `category_name`/`category_slug`
  overrides, keyed by blog_id.
- `option-keys.php` — per-plugin option-key patterns with migrate/exclude
  decisions (`PluginOptionRule`).
- `shortcode-ignore.php` — shortcode tag names the audit should not report
  (prose/dump noise), one per line, brackets optional.
- `term-overrides.php` — manual term-label merge decisions.

### DB endpoints (`src/Config/Endpoint/`)

`config.php` `"connection"` spec → `EndpointResolvers::forDriver()` → resolver
(`StaticEndpointResolver`, `LandoEndpointResolver`, `LocalEndpointResolver`)
→ `Endpoint` (host/port/socket) → `DatabaseConfig` → `Db\Connection` (PDO,
lazy). Adding a provider = one new resolver class + registration.

## Audit pipeline (`src/Audit/`)

`AuditRunner` runs each `AuditCheckInterface` check → `AuditFinding` list →
`SuppressionFilter` applies config `suppressions` (never silently — emits an
`audit.suppressed` summary finding) → `AuditReportWriter` renders Markdown +
JSON.

Checks in `src/Audit/Checks/`: `MediaFileCheck` (missing files, name/content
collisions), `OrphanedMediaFileCheck` (files with no attachment post),
`OrphanedMetaCheck`, `OrphanedPostAuthorCheck`, `OrphanedPostParentCheck`,
`PluginDataCheck` (option-keys rules vs. real data; delegates footprint
probing to `PluginFootprintDetector`), `MenuWidgetIntegrityCheck`,
`UserConflictCheck`, `TermCaseCollisionCheck` (previews `TermMergeResolver`),
`DivergentSiteOptionCheck`, `ContactPageDiscoveryCheck`, `PodsDetectionCheck`,
`MalwareIndicatorCheck` (backed by pure `MalwareHeuristics`).

## Content audit pipeline (`src/ContentAudit/`)

`PostScanner` queries `wp_{blogId}_posts` (+ postmeta grouped by post) with
`excluded_post_types` / explicit `--post-types` → `ScannedPost` (content +
`meta` as `meta_key => string[]` — values are arrays) → each
`ContentDetectorInterface` emits labels under its category → `ContentAuditRow`
(one row per post: blog_id, domain, post_id, post_type, post_status,
post_title, slug, original_url, then one column per detector category).
`excluded_post_statuses` config is merged over a built-in trash/auto-draft
exclusion (`postStatusClause()`).

Gotcha: MySQL caps a prepared statement at ~65535 placeholders — always
chunk `IN (...)` ID lists (see `fetchMetaForPosts()`, 5000/batch); a single
unbounded IN over a large site's posts fails outright.

Detectors (`src/ContentAudit/Detectors/`):

- `BlockDetector` — `<!-- wp:ns/name -->` comments; `core/*` and legacy
  `core-embed/*` filtered unless constructed with `includeCoreBlocks: true`.
- `ShortcodeDetector` — `[tag ...]` names; strips `<pre>/<code>`, requires
  lowercase first letter, skips `] =>` dump keys, merges built-in
  `IGNORED_TAGS` with `config/shortcode-ignore.php`.
- `PageBuilderDetector` — Elementor/Divi/Beaver/10Web meta + content sigs.
- `FormPluginDetector` — known form-plugin shortcodes/meta/classes; generic
  `<form>` labels carry the `action` URL; core search forms skipped.
- `GalleryDetector`, `VideoEmbedDetector`, `SeoPluginDetector`,
  `EcommerceDetector` — signature-based detectors for those plugin families.
- `NavMenuItemDetector` — for `nav_menu_item` rows only: reads
  `_menu_item_*` meta to report the link target (`page #123`,
  `custom: url`, `archive: x`). Labels are menu targets, not plugins — the
  rollup skips this category.
- `NeedsReviewDetector` — derived category: page builders, slideshow
  shortcodes, ecommerce/LMS post types, and any non-core block namespace
  (a page full of `uagb/*` breaks if Spectra isn't installed).

`PluginUsageRollup` groups detector labels into plugin entities via
`SIGNAL_MAP` (alias → display name + candidate slugs), `NOT_A_PLUGIN`
(core/platform labels), then slug-prefix matching against installed plugins.
Output: `used` / `not_installed` / `not_detected` (each entry carries
`has_data` + footprint summary from the callable) / `conflicts`
(`CONFLICT_FAMILIES`: same-role plugins co-active per site — SMTP, forms,
SEO, caching, image optimization, CDN, security, backups, page builders).

## Migration support (`src/Migration/`, `src/Destination/`)

- `SiteSelector` — `wp_blogs` rows → `Site` objects merged with `sites.php`
  include/exclude + category overrides.
- `PluginInventory` — installed slugs from `wp-content/plugins/` on disk +
  per-site/network `active_plugins` (serialized options; `wp_sitemeta` for
  network-active).
- `PluginFootprintDetector` — probes a slug's spellings across wp_options,
  postmeta, post types, and custom tables; `describe()` renders one line.
- `UploadsPathResolver` — `_wp_attached_file` → absolute path, both modern
  (`uploads/sites/{id}/`) and legacy `blogs.dir` layouts.
- `TermMergeResolver` + `TermMergeGroup` — pure, DB-free case-collision
  merge decisions (shared by the audit check and the future TermMigrator).
- `SitesPhpExporter` — Site list → paste-ready `sites.php` source.
- `Destination/AdminIdRenumberer` — SQL to move the admin off user ID 1.

## Reports (`src/Report/`)

- `AuditReportWriter` — findings → Markdown + JSON.
- `ContentAuditReportWriter` — rows → CSV, JSON, `-summary.md`, `.xlsx`
  (PhpSpreadsheet: wrapped text, ~5"-capped widths, bold filtered header,
  `blog_id`+`original_url` frozen; skipped when ext-zip is missing).
  Summary renders the `## Plugin usage` section first (used /
  not-installed / never-detected split by has_data / role conflicts).
- `NeedsReviewReportWriter` — focused CSV of pages needing manual edits:
  original URL, guessed destination URL, plugin labels, raw data via
  `RawContentExtractor`.
- `MissingMediaCopyScriptWriter` — missing-media findings + configured
  `media_search_paths` → bash `install -D` recovery script.

## Support (`src/Support/`)

`CliArguments` (`--key=value`/`--key value`/`--flag` parsing), `Logger`
(STDOUT/STDERR + `var/logs/`), `FileHasher`/`FileFingerprint` (size+hash
media dedup).

## Tests

`tests/Unit/` mirrors `src/`. Key convention: `ScannedPost` meta fixtures
are `meta_key => array('value')` — scalars make `metaValue()` return the
first character. `tests/bootstrap.php` sets up the autoloader.
