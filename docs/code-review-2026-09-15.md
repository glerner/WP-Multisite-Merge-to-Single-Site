# Code review — 3ce6bb1 (2026-09-15)

"Add plugin-usage rollup and XLSX output to site-audit; cut report noise across detectors"

## Summary

- Scope: Entire codebase (src/, bin/, config/, tests/), auditing security, code clarity, naming, and PLAN.md database migration reuse
- Date: 2026-09-15 (updated 2026-09-26)
- Status counts: 32 Done, 3 Pending, 8 N/A (Positive)

## Decisions / Constraints

- Findings must stay decision-oriented; suppression is configurable, never silent.
- `ScannedPost::$meta` is `meta_key => string[]`; test fixtures mirror this.
- The rollup is advisory — installed-but-undetected plugins are review candidates, not auto-removals.
- Always use `allowed_classes => false` on `unserialize()` for database data (untrusted input).
- Extract reusable data-gathering and planning helpers into `src/Migration/` so both audit tools and database migrators share tested code without duplication.

## Confirmed Meanings in Code

- `ScannedPost`: Represents a post scanned from source multisite with its per-site meta, terms, and post titles.
- `IdMap`: Central memory + JSON state registry mapping `(type, siteId, oldId) -> newId`.
- `MigrationTable`: Destination table `{prefix}merge_migration_map` recording origin IDs for idempotent re-runs.
- `PostQueryHelper`: Shared builder for `post_status` and `post_type` WHERE clauses and chunked postmeta queries.
- `TermInventory` + `TermMergeResolver`: Collects term rows across sites and resolves cross-site canonical labels.

## Unified Action Checklist

- CR-001 — Status: Done · Priority: MEDIUM
  - Finding: `PluginUsageRollup::resolveEntity()` checks `NOT_A_PLUGIN` by prefix before consulting installed slugs. A condensed label like `gallerypro` or `youtubeembedplus` starts with the `gallery`/`youtube` tokens and was silently dropped.
  - Impact: False "safest removal candidate" for a plugin actually in use.
  - Source refs: `src/ContentAudit/PluginUsageRollup.php:resolveEntity()`.
  - Fix: Installed slugs are checked first; `NOT_A_PLUGIN` only evaluated when no installed slug matched.

- CR-003 — Status: Done · Priority: LOW
  - Finding: In `bin/site-audit.php`, a network-active plugin that also appears in a site's own `active_plugins` option gets that blog_id appended twice to `$activeBySlug`.
  - Impact: Latent surprise for any future consumer that counts instead of dedupes.
  - Source refs: `bin/site-audit.php`.
  - Fix: Deduplicated via keyed set `$activeBySlug[$slug][$site->blogId] = $site->blogId`.

- CR-101 — Status: Done · Priority: MEDIUM
  - Finding: `MenuWidgetIntegrityCheck` joined `_menu_item_object_id` against the posts table only. Taxonomy menu items store a `term_id` there (`wp_terms`), so "Category X" menu links were falsely reported as missing objects.
  - Source: `src/Audit/Checks/MenuWidgetIntegrityCheck.php:48-53`.
  - Fix: Reads `_menu_item_type` first: `'taxonomy'` checks `wp_terms.term_id`, `'post_type'` joins posts table, skips `'custom'`/`'post_type_archive'`.

- CR-102 — Status: Done · Priority: MEDIUM
  - Finding: `orphanedDataFinding()` suggested pasting `['check' => 'plugin-data.orphaned-data', 'plugin' => '<slug>']` into suppressions, but context had no `'plugin'` key.
  - Source: `src/Audit/Checks/PluginDataCheck.php`, `src/Audit/SuppressionFilter.php`.
  - Fix: Emits findings with matching context keys.

- CR-103 — Status: Done · Priority: MEDIUM
  - Finding: `MediaFileCheck` fingerprinted every found file twice in `checkFilenameCollisions()` and `checkDuplicateContentDifferentNames()`.
  - Source: `src/Audit/Checks/MediaFileCheck.php:184-187,240-242`.
  - Fix: Fingerprints once in `run()`, shares the map.

- CR-104 — Status: Done · Priority: MEDIUM
  - Finding: `fetchMetaForPosts()` built a single `IN(...)` placeholder list; MySQL caps prepared statements at 65535 placeholders, so sites with >65k posts failed.
  - Source: `src/ContentAudit/PostScanner.php:260-276`.
  - Fix: Chunk post IDs into 5000 per query.

- CR-105 — Status: Done · Priority: MEDIUM
  - Finding: Needs-review CSV `post_title` column was empty because it read `$row->categoryFindings['post_title']` instead of `$row->postTitle`.
  - Source: `src/Report/NeedsReviewReportWriter.php:105`.
  - Fix: Uses `$row->postTitle`.

- CR-106 — Status: Done · Priority: MEDIUM
  - Finding: Duplicate of CR-001 (`PluginUsageRollup::resolveEntity()`). Fixed together with CR-001.
  - Source: `src/ContentAudit/PluginUsageRollup.php`.

- CR-107 — Status: Done · Priority: MEDIUM
  - Finding: Beaver Builder gallery signature in `_fl_builder_data` is PHP-serialized (`s:4:"type";s:7:"gallery"`), so raw JSON substring search never matched.
  - Source: `src/ContentAudit/Detectors/GalleryDetector.php:42-46`.
  - Fix: Deserializes with `allowed_classes => ['stdClass']` and recursively checks node types.

- CR-108 — Status: Done · Priority: MEDIUM
  - Finding: `excluded_post_statuses` was a dead config key: loaded by `ConfigLoader` but hardcoded in `PostScanner`.
  - Source: `src/Config/ConfigLoader.php`, `src/ContentAudit/PostScanner.php`.
  - Fix: Wired into `postStatusClause()`.

- CR-109 — Status: Done · Priority: LOW
  - Finding: `harden-admin-id.php` wrapped UPDATEs and `ALTER TABLE` in one transaction. In MySQL, `ALTER TABLE` (DDL) implicitly commits before executing, making rollback impossible if ALTER failed.
  - Source: `bin/harden-admin-id.php:105-125`, `src/Destination/AdminIdRenumberer.php:52-87`.
  - Fix: Separated DML (`UPDATE`s) and DDL (`ALTER TABLE`) in `AdminIdRenumberer` via `buildDmlStatements()` and `buildDdlStatements()`; `harden-admin-id.php` runs DML inside a transaction that commits atomically first, then executes DDL.

- CR-110 — Status: Done · Priority: LOW
  - Finding: `findOrphanedPluginData` executed `break;` after the first matching pattern per rule, preventing detection of additional patterns under the same plugin rule.
  - Source: `src/Audit/Checks/PluginDataCheck.php:296`.
  - Fix: Removed `break;` so all patterns are checked.

- CR-111 — Status: Done · Priority: LOW
  - Finding: `NeedsReviewDetector` copy-pasted `PageBuilderDetector`'s signature checks, risking silent divergence.
  - Source: `src/ContentAudit/Detectors/NeedsReviewDetector.php:32-43`.
  - Fix: Delegated directly to `(new PageBuilderDetector())->detect($post)`.

- CR-112 — Status: Done · Priority: LOW
  - Finding: `VideoEmbedDetector` missed modern Gutenberg `<!-- wp:embed -->` blocks with YouTube/Vimeo URLs, `youtube.com/embed`, `youtube.com/shorts`, `youtube-nocookie.com`, and `player.vimeo.com`.
  - Source: `src/ContentAudit/Detectors/VideoEmbedDetector.php:25-31`.
  - Fix: Expanded regexes and added unit test suite `tests/Unit/ContentAudit/VideoEmbedDetectorTest.php`.

- CR-113 — Status: Done · Priority: LOW
  - Finding: `DivergentSiteOptionCheck` only scans `autoload = 'yes'` options, but this constraint was not noted in docblock or description.
  - Source: `src/Audit/Checks/DivergentSiteOptionCheck.php:120`.
  - Fix: Clarified in `description()` and docblock that only autoloaded options are audited.

- CR-114 — Status: Done · Priority: LOW
  - Finding: `DivergentSiteOptionCheck` unbounded string implosion in `.truncated` info finding when >50 options diverged.
  - Source: `src/Audit/Checks/DivergentSiteOptionCheck.php:213-218`.
  - Fix: Capped at sample of 20 option names plus count of remaining options.

- CR-115 — Status: Done · Priority: LOW
  - Finding: `MenuWidgetIntegrityCheck::checkWidgets` queried `SELECT option_value FROM options WHERE option_name = :name` for every widget instance (N+1 queries for multiple widgets of the same type).
  - Source: `src/Audit/Checks/MenuWidgetIntegrityCheck.php:144`.
  - Fix: Cached fetched widget option values in `$widgetOptionsCache` per site.

- CR-116 — Status: Done · Priority: LOW
  - Finding: `MalwareIndicatorCheck::checkSpamKeywordsInContent` loaded all published post contents in memory in a single `fetchAll()`.
  - Source: `src/Audit/Checks/MalwareIndicatorCheck.php:250-253`.
  - Fix: Chunked post scanning using `ID > :last_id ORDER BY ID ASC LIMIT 500`.

- CR-117 — Status: Done · Priority: LOW
  - Finding: `PostScanner::searchSite` stopped at first matching keyword per post, discarding secondary matches.
  - Source: `src/ContentAudit/PostScanner.php:140-159`.
  - Fix: Gathers all matched keywords per post and joins with commas (`cialis, viagra`). Replaced ambiguous `$matched !== array()` check with explicit `! empty( $matched )`.

- CR-118 — Status: Done · Priority: LOW
  - Finding: `PluginDataCheck` called `installedSlugs()` and `$mustUse` inside the per-site loop, rescanning filesystem plugins on every site.
  - Source: `src/Audit/Checks/PluginDataCheck.php:74-76`.
  - Fix: Hoisted `$installed` filesystem discovery outside the per-site loop.

- CR-119 — Status: Done · Priority: LOW
  - Finding: `DatabaseConfig::fromArray` required `'host'` even when `'unix_socket'` was supplied.
  - Source: `src/Config/DatabaseConfig.php:61`.
  - Fix: Made `'host'` optional when `'unix_socket'` is provided; added unit test.

- CR-120 — Status: Done · Priority: LOW
  - Finding: `expandHome()` applied to `media_search_paths` but not `uploads_path` in `ConfigLoader`.
  - Source: `src/Config/ConfigLoader.php:45-48`.
  - Fix: Applied `expandHome()` to source and destination `uploads_path`.

- CR-121 — Status: Pending · LOW
  - Finding: `ContentAuditRow::$original_url` is approximate: built from `post_name` alone, so hierarchical child pages (e.g. `/parent/child/`) lose parent path.
  - Source: `src/ContentAudit/ContentAuditRow.php:55`.
  - What remains: Build parent-child slug tree in `PostScanner` when full hierarchical URL is required for reports.

- CR-122 — Status: Done · Priority: LOW
  - Finding: `Logger` silently dropped file logging if `fopen` failed without warning.
  - Source: `src/Support/Logger.php:49-51`.
  - Fix: Emits warning to `STDERR` if `$handle === false`.

- CR-123 — Status: Done · Priority: LOW
  - Finding: `MissingMediaCopyScriptWriter::locate()` returned the first candidate when none matched the relative path, risking restoring the wrong file without notice. Test fixtures also used positional integers (`60, 100`) without clear labels.
  - Source: `src/Report/MissingMediaCopyScriptWriter.php:140-165`, `tests/Unit/Report/MissingMediaCopyScriptWriterTest.php`.
  - Fix: Added `# AMBIGUOUS` script comment and candidate list when multiple basename candidates exist but none match relative path. Converted test calls to PHP 8 named arguments (`blogId: 60, postId: 100`) with docblock explanations. Added unit test.

- CR-124 — Status: Done · Priority: LOW
  - Finding: `PodsDetectionCheck` used `TABLE_NAME LIKE '%pods%'`, matching unrelated tables such as `wp_podcasts`.
  - Source: `src/Audit/Checks/PodsDetectionCheck.php:39`.
  - Fix: Regex-filters table names using `/(?:^|_)pods(?:rel|meta|_|$)/i` to match only true Pods tables.

- CR-125 — Status: Done · Priority: LOW
  - Finding: `CliArguments` `--key value` form swallowed the next non-flag arg; positional arguments could not be distinguished from option values.
  - Source: `src/Support/CliArguments.php:40-44`.
  - Fix: Added optional `$booleanFlags` parameter so boolean flags never consume following arguments, and exposed all non-option positional arguments via `positional()`. Added unit test suite `tests/Unit/Support/CliArgumentsTest.php`.

- CR-126 — Status: Done · Priority: LOW
  - Finding: `AdminIdRenumberer::pickRandomId` looped infinitely if `$min === $max && $min === $oldId`.
  - Source: `src/Destination/AdminIdRenumberer.php:41-45`.
  - Fix: Added upfront check throwing `\InvalidArgumentException`; added unit test.

- CR-127 — Status: Done · Priority: LOW
  - Finding: `Site::fromBlogRow` inconsistently used `$override?->categorySlug` while using `-> ... ??` on other properties.
  - Source: `src/Migration/Site.php:65`.
  - Fix: Cleaned up to `$override->categorySlug ?? null`.

- CR-201 — Status: Done · Priority: HIGH
  - Finding: Untrusted database options and postmeta were passed to `unserialize()` without `['allowed_classes' => false]` in `PluginInventory.php` (lines 201, 214), `UserMigrator.php` (line 83), `OrphanedMediaFileCheck.php` (line 134), and `MenuWidgetIntegrityCheck.php` (lines 127, 172).
  - Impact: PHP Object Injection risk. Unserializing untrusted database data without restricting classes can trigger arbitrary object instantiation and destructor gadget chains.
  - Source refs: `src/Migration/PluginInventory.php`, `src/Migration/UserMigrator.php`, `src/Audit/Checks/OrphanedMediaFileCheck.php`, `src/Audit/Checks/MenuWidgetIntegrityCheck.php`.
  - Fix: Added `array( 'allowed_classes' => false )` to all database deserialization points.

- CR-202 — Status: Done · Priority: MEDIUM
  - Finding: `PostScanner` encapsulated post query clauses (`postStatusClause`, `postTypeClause`) and 5000-chunked postmeta fetching as private methods. `PostMigrator.php` (PLAN.md §7.1) needs the identical query clauses and postmeta fetching.
  - Impact: Duplication of SQL clause building and parameter binding across scanner and migrator.
  - Source refs: `src/ContentAudit/PostScanner.php:273-352`.
  - Fix: Extracted `src/Migration/PostQueryHelper.php` (`postStatusClause()`, `postTypeClause()`, `fetchMetaForPosts()`); `PostScanner` now calls `PostQueryHelper`. Added unit test suite `tests/Unit/Migration/PostQueryHelperTest.php`.

- CR-203 — Status: Pending · Priority: MEDIUM (Plan presented)
  - Finding: `MediaFileCheck` gathers attachment posts, relative paths (`_wp_attached_file`), resolves disk paths via `UploadsPathResolver`, hashes files, groups by basename, and detects collisions. `MediaMigrator.php` (PLAN.md §7.2) requires this exact discovery and collision renaming logic (`{basename}_site{old_blog_id}.{ext}`).
  - Impact: Code duplication between audit and migration tools.
  - Source refs: `src/Audit/Checks/MediaFileCheck.php:60-91, 176-232`.
  - What remains: Extract `MediaInventory` / `MediaCollisionPlan` in `src/Migration/` to be shared between `MediaFileCheck` and `MediaMigrator`.

- CR-204 — Status: Pending · Priority: MEDIUM (Plan presented)
  - Finding: PLAN.md §5 specifies `SerializedDataRewriter` to safely unserialize, walk, remap IDs/URLs, and re-serialize with updated byte-length prefixes for postmeta, options, and block attributes. Not yet written.
  - Impact: Required before `PostMigrator`, `CommentMigrator`, and `OptionsMigrator` can safely remap IDs in serialized data without corrupting PHP serialized strings.
  - Source refs: `PLAN.md` §5 (lines 252-257).
  - What remains: Implement `src/Migration/SerializedDataRewriter.php` with recursion guards, integer/string remapping callbacks, and unit tests against real WP serialized fixtures.

- CR-205 — Status: Done · Priority: LOW
  - Finding: `SitesPhpExporter.php` output short array syntax `[` and `]` instead of WordPress array syntax `array(...)` specified in `AGENTS.md`.
  - Impact: Formatting inconsistency with `config/sites.sample.php`.
  - Source refs: `src/Migration/SitesPhpExporter.php:45, 56, 79`.
  - Fix: Updated `SitesPhpExporter` and `SitesPhpExporterTest` to output WordPress array syntax `array(...)`.

- CR-206 — Status: Done · Priority: LOW (from "Do After")
  - Finding: XLSX reports used default 11pt font size. Important spreadsheet data needed at least 12pt font for readability.
  - Source refs: `src/Report/ContentAuditReportWriter.php:456`.
  - Fix: Set default font size to 12pt (`$spreadsheet->getDefaultStyle()->getFont()->setName('Consolas')->setSize(12)`).

## Positive Observations (N/A)

- `AuditRunner` isolates check crashes into error findings instead of aborting the run (`AuditRunner.php:40-49`).
- `SuppressionFilter` never hides silently — the `audit.suppressed` summary finding records that filtering happened (`SuppressionFilter.php:52-61`).
- `PluginInventory` correctly detects real plugins via "Plugin Name:" headers rather than trusting directory names — kills vendor/ and dotfile noise.
- `UploadsPathResolver` handles both modern `uploads/sites/{id}/` and legacy `blogs.dir` layouts with a documented candidate order.
- Word-boundary spam matching (`PostScanner`, `MalwareHeuristics`) already fixes the "cialis in specialist" false positive class.
- Config validation errors name the offending key and section; connection failures print actionable per-driver troubleshooting (`Connection.php:74-98`).
- `MissingMediaCopyScriptWriter` copies into the SOURCE tree deliberately so the migrator's own collision-rename still applies — the subtle right call.
- `UserMigrator` is cleanly written with clear transactions, role hierarchy resolution (`RoleResolver`), duplicate prevention, and `IdMap`/`MigrationTable` recording.

## Plan for Larger Future Extractions (PLAN.md Migration Reuse)

Note: CR-* numbers refer to sections above; these are the plan for implementing them:

1. **`MediaInventory` & `MediaCollisionPlan` (CR-203)**:
   - Extract attachment post querying, disk path resolution, fingerprinting, and collision planning from `MediaFileCheck` into `src/Migration/MediaInventory.php`.
   - Provide methods:
     - `collectAttachments(Connection $source, array $sites, UploadsPathResolver $resolver): MediaInventoryResult`
     - `planCollisions(array $attachments, FileHasher $hasher): MediaCollisionPlan`
   - `MediaFileCheck` uses the plan to report errors/info; `MediaMigrator` uses it to copy/rename physical files and insert attachment posts into destination.

2. **`SerializedDataRewriter` (CR-204 / PLAN.md §5)**:
   - Build `src/Migration/SerializedDataRewriter.php` with:
     - `rewrite(string $serialized, callable $idRemapper, callable $urlRemapper): string`
     - Safely parses nested serialized structures with `allowed_classes => false` (or dedicated safe string walker), updates array keys/values, and recalculates string length markers `s:length:"value"`.
   - Reusable across `PostMigrator` (postmeta), `CommentMigrator` (commentmeta), and `OptionsMigrator` (options and widgets).
