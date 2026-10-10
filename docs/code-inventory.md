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

| File                                  | Purpose                                                                                                                                                                                                                                                                                         |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `bin/migrate.php`                     | Migration entry point: runs the implemented phases in dependency order (users → terms → media → posts). `--dry-run`, `--move-media-only`, `--site=`, `--config=`. Writes run reports to `var/reports/`, logs to `var/logs/`, ID-map snapshots to `var/state/`.                                  |
| `bin/site-audit.php`                  | Content/plugin-usage audit: scans posts across included sites, reports blocks/shortcodes/plugin footprints per page. Options: `--site=<id>`, `--all-sites`, `--post-types=…`, `--search=…`, `--config=<dir>`. Writes CSV + JSON + summary.md + XLSX to `var/reports/`, plus a needs-review CSV. |
| `bin/multisite-integrity-checker.php` | Read-only pre-flight audit: runs every `AuditCheckInterface` check. `--strict`, `--list-sites`, `--config=`. Writes Markdown + JSON to `var/reports/` and a media-recovery script to `var/`.                                                                                                    |
| `bin/harden-admin-id.php`             | Renumbers destination admin user away from ID 1 (run once, before migration; see `AdminIdRenumberer`).                                                                                                                                                                                          |
| `bin/test-connections.php`            | Pre-flight connection test: verifies both source and destination databases and uploads directories simultaneously in one PHP process.                                                                                                                                                           |
| `bin/run-wpscan.php`                  | Optional wrapper around external `wpscan` CLI (Ruby gem) for vulnerability checks against a live URL.                                                                                                                                                                                           |

## Configuration (`config/`)

`ConfigLoader` reads `config.php` (required) plus optional
`sites.php`, `option-keys.php`, `term-overrides.php`, `shortcode-ignore.php`,
`plugin-roles.php`, `divergent-options.php` and produces one `MergeConfig`.
All real config files are gitignored; each has a `*.sample.php` committed.

- `config.php` — DB endpoints, `destination_url`,
  `audit_excluded_post_types` (audit/report suppression only — does
  NOT stop migration), `migration_excluded_post_types` (the only thing
  that stops migration: junk/leftover types; everything else migrates),
  `excluded_post_statuses`, `term_merge_rule`, `main_site` (blog_id
  whose variant wins merge conflicts), `contact_page_paths`,
  `suppressions` (finding-suppression rules; merged with
  plugin-roles.php's `suppressions` section), `media_search_paths`,
  `spreadsheet_format`, `wpscan_api_token`.
- `sites.php` — per-site `include` bool + `category_name`/`category_slug`
  overrides, keyed by blog_id. `deleted` may appear on exported entries
  as an informational marker; it is ignored on load.
- `option-keys.php` — per-plugin option-key EXCEPTION list
  (`PluginOptionRule`). Options migrate by default; entries only say
  "not this one" or "only partly": `exclude`, `include_partial` +
  `exclude_subkeys`, `exclude_option_keys` for individual drops under
  an included prefix, `needs_adapter`. Credential-named options/sub-keys
  (`PluginOptionRule::isSensitiveKeyName` — password/secret/key/token/
  salt/nonce/license/credential as `_`-separated segments) DO migrate —
  the destination plugin needs them — but are flagged in the report so
  no secret travels invisibly. Runtime state never needs an entry:
  `PluginOptionRule::BUILT_IN_EXCLUDED_OPTION_PATTERNS`
  (Action Scheduler locks/demarkation, `_transient_*`/`_site_transient_*`
  cache rows) is applied by the migrator on every site.
- `shortcode-ignore.php` — shortcode tag names the audit should not report
  (prose/dump noise), one per line, brackets optional.
- `term-overrides.php` — manual term-label merge decisions.
- `plugin-roles.php` — overrides for `PluginUsageRollup`: add/extend
  `conflict_families` (`'-slug'` removes a built-in member), `signal_map`
  aliases, and `not_a_plugin` tokens; plus `detector_extras` —
  per-detector signature-table additions keyed by each detector's
  `category()` (`seo_plugin`, `form_plugin`, `gallery`, `shortcodes`);
  plus `suppressions` — plugin-specific finding suppressions (long form,
  or `'check' => array( slug, ... )` shorthand), merged with config.php's
  `suppressions`. Augments the built-ins.
- `divergent-options.php` — extra `wp_options.option_name` exclusions
  for the `divergent-site-options` check, on top of the built-in
  `EXCLUDED_OPTION_NAMES` list.

### DB endpoints (`src/Config/Endpoint/`)

`config.php` `"connection"` spec → `EndpointResolvers::forDriver()` → resolver
(`StaticEndpointResolver`, `LandoEndpointResolver`, `LocalEndpointResolver`)
→ `Endpoint` (host/port/socket) → `DatabaseConfig` → `Db\Connection` (PDO,
lazy). Adding a provider = one new resolver class + registration.

`Db\Connection` is `final`; its test-only `forTesting(PDO)` factory injects a
pre-built PDO (skipping the `mysql:` DSN path) so SQLite fixtures can drive
every consumer. It is driver-aware where SQL dialects differ:
`driverName()`, `tableExists()` (`information_schema` on MySQL,
`sqlite_master` on SQLite), and `insertIgnore()` (`INSERT IGNORE` /
`INSERT OR IGNORE`, used by `MigrationTable::record()`).

## Audit pipeline (`src/Audit/`)

`AuditRunner` runs each `AuditCheckInterface` check → `AuditFinding` list →
`SuppressionFilter` applies `suppressions` from config.php + plugin-roles.php
(merged; never silently — emits an
`audit.suppressed` summary finding) → `AuditReportWriter` renders Markdown +
JSON.

Checks in `src/Audit/Checks/`: `MediaFileCheck` (missing files, name/content
collisions), `OrphanedMediaFileCheck` (files with no attachment post),
`OrphanedMetaCheck`, `OrphanedPostAuthorCheck`, `OrphanedPostParentCheck`,
`PluginDataCheck` (option-keys rules vs. real data; delegates footprint
probing to `PluginFootprintDetector`), `MenuWidgetIntegrityCheck`,
`UserConflictCheck`, `TermCaseCollisionCheck` (previews `TermMergeResolver`),
`TemplateSlugCollisionCheck` (same-slug wp_template/wp_template_part across
sites; `main_site` wins), `DivergentSiteOptionCheck` (autoloaded options that
differ across sites; Markdown shows 50, the uncapped option/value/site map
rides the `.truncated` finding's context into the `divergent-options` tab of
`integrity-*.xlsx`),
`ContactPageDiscoveryCheck`, `PodsDetectionCheck`,
`MalwareIndicatorCheck` (backed by pure `MalwareHeuristics`).

## Content audit pipeline (`src/ContentAudit/`)

`PostScanner` queries `wp_{blogId}_posts` (+ postmeta grouped by post) with
`audit_excluded_post_types` / explicit `--post-types` → `ScannedPost` (content +
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
- `DetectorExtras` — merge helpers for `plugin-roles.php`
  `detector_extras`: `patternMap` (label → regex[], appends; validates
  every merged pattern compiles under `/…​/i` and throws
  `InvalidArgumentException` naming the label + pattern, so a config
  regex typo like an unescaped `/` fails at startup instead of
  flooding "Unknown modifier" warnings and silently never matching),
  `tupleMap` (label → [meta_key, needle], replaces per label),
  `prefixMap` (prefix → label, replaces per prefix). The table-driven
  detectors (`SeoPluginDetector`, `FormPluginDetector`, `GalleryDetector`)
  merge extras over their built-in signature constants in the
  constructor; `ShortcodeDetector`'s `ignored_tags` extra merges into
  `ignoredShortcodes` at the call site in `bin/site-audit.php`.

`PluginUsageRollup` groups detector labels into plugin entities via
`SIGNAL_MAP` (token → `name` + `slugs` candidates), `NOT_A_PLUGIN`
(core/platform labels), then slug-prefix matching against installed plugins.
All three built-in lists are augmented by `config/plugin-roles.php`
(constructor arg). Output: `used` / `not_installed` / `not_detected`
(each entry carries `has_data` + footprint summary from the callable) /
`conflicts` (`CONFLICT_FAMILIES`: same-role plugins co-active per site —
SMTP, forms, SEO, comment spam, caching, image optimization, CDN,
security, backups, page builders).

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
- `MediaInventory` — per-site attachment collection: `_wp_attached_file`
  rows → `{found, missing}` with resolved disk paths, plus once-per-file
  fingerprinting (used by `MediaFileCheck`; `MediaMigrator` fetches its
  own post rows + meta so it can recreate the posts).
- `MediaCollisionPlan` — pure, DB-free dedup/rename decisions: groups files
  by basename or fingerprint, resolves destination-relative targets, and
  applies `{basename}_site{blog_id}.{ext}` renames (including `-{W}x{H}`
  thumbnail variants).
- `MediaMigrator` — Phase 4 (§7.2): copies files (fingerprint dedup +
  collision renames, destination-aware), recreates `attachment` posts +
  postmeta (`_wp_attached_file`/`_wp_attachment_metadata` rewritten via
  `SerializedDataRewriter`), records mappings in IdMap/MigrationTable.
  Pure planning (`planTargets`, `rewriteAttachmentMetadata`,
  `variantBasenames`, `collisionAlternatives`) unit-tested; the DB +
  filesystem execution paths are exercised end-to-end by
  `tests/Integration/MediaMigratorEndToEndTest.php` on the SQLite fixture
  schema.
- `PostMigrator` — Phase 5 (§7.1): copies posts/pages/CPTs + postmeta +
  term_relationships. Destination slug dedup (WP-style `-2`/`-3`) +
  `/contact/` canonicalization via `ContactPageCanonicalizer`
  (`destinationSlug()` is public pure logic, unit-tested); block
  attributes remapped via `BlockAttributeRewriter` (`ref`→post,
  `id`/`ids`→attachment); `_thumbnail_id` remapped via IdMap; every
  post gets its site category + original categories/tags; two-pass
  parent fixups (posts + attachment parents MediaMigrator deferred);
  per-site `term_taxonomy.count` refresh; trash discarded (counted as
  `trash_skipped`), drafts migrate, `future` posts listed in
  `future_posts` for the §7.1 schedule-future-posts workflow.
  Type exclusion comes only from `migration_excluded_post_types`
  (+ built-ins owned by other phases) — `audit_excluded_post_types` is
  audit-only and does not block migration.
  Idempotent via 'post' entries in IdMap/MigrationTable.
- `MenuInventory` — per-site `nav_menu` terms + `nav_menu_item` posts with
  their `_menu_item_*` meta (type/object/object_id/url/parent) and menu
  `term_taxonomy_id`; shared by `MenuWidgetIntegrityCheck`/`MenuMigrator`.
- `WidgetInventory` — parses `sidebars_widgets` + all `widget_%` options
  (`{sidebars, sidebars_corrupt, widget_options}`); `parseWidgetId()` splits
  `{type}-{index}` IDs. Shared by `MenuWidgetIntegrityCheck`/`MenuMigrator`.
- `PostHierarchyResolver` — `slugPath()` walks same-post-type
  `post_parent` chains into `parent/child` paths; `PostScanner` uses it for
  `ContentAuditRow.path` → accurate `original_url`.
- `CommentQueryHelper` — keyset-paginated comment batches +
  chunked `commentmeta` fetching (mirrors `PostQueryHelper`).
- `ContactPageCanonicalizer` — shared "contact" slug detection
  (`isContactLike()`/`candidateClause()`) + variant→`/contact/` mapping
  (`isVariant()`/`canonicalPath()`); used by `ContactPageDiscoveryCheck`.
- `RedirectMapBuilder` — pure redirect-map formatters for all 6 exports
  (JSON, Markdown, Redirection CSV, Yoast CSV, .htaccess, nginx.conf) +
  `writeAll()`.
- `BlockAttributeRewriter` — rewrites `<!-- wp:name {json} -->` block
  attributes (default `ref`) via `IdMap` for PostMigrator/MenuMigrator.
- `GuidGenerator` — destination post GUIDs: `?p={id}` for posts, uploads
  URL for attachments.
- `TermMergeResolver` + `TermMergeGroup` — pure, DB-free case-collision
  merge decisions (shared by the audit check and the future TermMigrator).
- `SitesPhpExporter` — Site list → paste-ready `sites.php` source
  (deleted sites included, marked `include=false` + `deleted=true`).
- `Destination/AdminIdRenumberer` — SQL to move the admin off user ID 1.
- `TemplateInventory` — wp_template/wp_template_part rows per site, each
  with its `wp_theme` term (active stylesheet, stale theme, or
  "plugin/file") and post_content.
- `TemplateContext` + `TemplateContextCollector` — per-site template
  environment (stylesheet/parent, show_on_front, front/posts page,
  child+parent theme template slugs, part→template usage map,
  slug→URL map); the collector reads options/posts and theme files.
  Parent theme comes from the child theme's `Template:` style.css
  header, not the `template` option (which can be stale after a
  manual theme switch — the raw option is kept on the context so
  the bin scripts can warn on mismatch).
- `TemplateShotPlan` — pure shot planner for `bin/template-screenshots.php`;
  shoots only templates/parts that actually render on a reachable page.
- `PageTemplateResolver` — per-post template resolution
  (`_wp_page_template` or hierarchy) + status for the audit's
  `template`/`template_status` columns; sites whose effective theme
  has no `templates/index.html` (child or parent) are labeled
  `classic theme`/`classic-theme` instead of guessing.
- `TemplatePartRefs` — parses `wp:template-part` slug refs out of block
  markup (DB rows and theme .html files).

## Reports (`src/Report/`)

- `AuditReportWriter` — findings → Markdown + JSON.
- `DivergentOptionsReportWriter` — the uncapped divergent-options map →
  `integrity-*.xlsx` "divergent-options" tab (option_name / key / value /
  site_ids); falls back to same-named CSV when ext-zip is missing.
  Options whose values are all serialized arrays are exploded into one
  row per *diverging* sub-key (identical keys skipped, missing keys
  shown `(absent)`); scalar options stay one row per whole value.
  .xlsx rows are height-capped at 4″ (`MAX_ROW_HEIGHT_PT`).
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
- `SpreadsheetRowHeight` — shared .xlsx row-height cap (4″): estimates
  wrapped lines per cell and fixes the row height only when content
  would exceed the cap; applied by both report writers on every
  worksheet.

## Support (`src/Support/`)

`CliArguments` (`--key=value`/`--key value`/`--flag` parsing), `Logger`
(STDOUT/STDERR + `var/logs/`), `FileHasher`/`FileFingerprint` (size+hash
media dedup).

## Tests

`tests/Unit/` mirrors `src/`. Key convention: `ScannedPost` meta fixtures
are `meta_key => array('value')` — scalars make `metaValue()` return the
first character. `tests/bootstrap.php` sets up the autoloader.

`tests/Support/` holds the SQLite test harness that lets DB-bound classes
run their real SQL against a throwaway in-memory database instead of the
configured Source/Destination: `WpTestSchema` builds
WordPress-shaped schemas (`wp_posts`, `wp_postmeta`, `wp_terms`,
`wp_term_taxonomy`, `wp_term_relationships`, `wp_options`, `wp_comments`,
`wp_commentmeta`, plus `{prefix}{blogId}_*` multisite variants and the
network `users`/`usermeta`/`blogs`/`sitemeta`) on `new PDO('sqlite::memory:')`
with row-insert helpers, and `SqliteTestCase` skips cleanly when pdo_sqlite
is absent while providing connection/config factories.

`tests/Integration/` exercises the DB-bound classes against those fixtures
(they never touch configured Source/Destination databases): inventory
queries (`MediaInventory`, `MenuInventory`, `WidgetInventory::collect`),
chunked meta fetching (`PostQueryHelper`, `CommentQueryHelper`, including
the 5000-id IN() boundary), `MigrationTable` (dialect-split DDL +
`INSERT OR IGNORE` idempotency), `MediaMigrator` end-to-end (collision
renames + variants, hash dedup, dry-run, resume), and `PostMigrator`
end-to-end (slug dedup, contact canonicalization, `_thumbnail_id`/block
remapping, parent fixups, term counts, trash/future, dry-run, resume).
