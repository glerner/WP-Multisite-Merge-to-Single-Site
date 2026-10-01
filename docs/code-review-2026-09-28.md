# Code Review — 2026-09-28

## Summary

- Scope: Diff review post-commit plus complete architectural review of PLAN.md (Phases 3–10) to identify reusable migration extractions, deduplicate queries, and prepare well-tested components.
- Date: 2026-09-28
- Status counts: 4 Done, 9 Pending (Migration Extractions), 3 Future Enhancements, 4 Positive (N/A)

## Decisions / Constraints

- Destination is strictly a single WordPress site, not a multisite network (multisite-to-multisite consolidation is an explicit non-goal; table prefix targets single-site core tables `%susers`, `%sposts`, `%scomments`).
- Missing media resolution: In ambiguous cases where multiple candidate files match the basename but none match the full relative path, emit commented `install` commands (`# install -D -m 0644 ...`) for all candidates so manual intervention is required. Print a prominent header message instructing the user to uncomment one candidate or rename the source files.
- Widget integrity: Corrupted/malformed serialized widget option rows must not be silently skipped; emit an audit warning finding so the site owner knows the widget configuration needs rebuilding.
- Query clause construction: SQL WHERE fragments produced by helper classes must be composable and valid whether called individually or in combination.
- Shared Migration Components: Extract reusable data-gathering, collision-planning, and remapping logic into `src/Migration/` before writing migration executors (`migrate.php`), so both audit tools and migrators share thoroughly unit-tested code without duplication.

## Unified Action Checklist — Diff & Check Findings

- CR-301 — Status: Done · Priority: MEDIUM
  - Finding: `PostQueryHelper::postTypeClause()` hardcodes a leading ` AND post_type IN (...)` (or ` AND post_type NOT IN (...)`), assuming that another WHERE fragment (such as `postStatusClause()`) always precedes it. If called standalone or reordered, it generates invalid SQL: `WHERE  AND post_type IN (...)`.
  - Impact: Syntax errors in upcoming migrators (`PostMigrator`) or other query callers that use `postTypeClause()` independently.
  - Source refs: `src/Migration/PostQueryHelper.php:61,75`.
  - Fix: Stripped the leading ` AND ` from `postTypeClause()`, added `PostQueryHelper::postsWhereClause()` to cleanly combine status and type clauses, and updated `PostScanner` and unit tests.

- CR-302 — Status: Done · Priority: MEDIUM
  - Finding: When `$located['ambiguous']` is true (multiple files match the basename, none match the relative path), `MissingMediaCopyScriptWriter` emitted an `# AMBIGUOUS:` comment but still output an active `install -D -m 0644 $found $target` command using the first candidate.
  - Context & Scenarios: Commonly occurs when sites import images from digital camera memory cards with generic sequential numbering (`IMG_0001.JPG`, `DSC_0001.JPG`, `photo.jpg`) stored in different folders across years, or when site duplication/export tools copy assets across multiple directories.
  - Impact: Executing the generated script without manual editing blindly copies the first candidate, risking overwriting media with an unrelated file sharing the same basename.
  - Fix: Emits a prominent banner warning that manual intervention is required, comments out all candidate `install` lines (`# install -D -m 0644 ...`), and omits any active command. Updated unit tests.
  - Source refs: `src/Report/MissingMediaCopyScriptWriter.php:91-115`.

- CR-303 — Status: Done · Priority: LOW
  - Finding: In `MenuWidgetIntegrityCheck::checkWidgets()`, if `@unserialize()` fails and returns `false` due to corrupted or malformed option data in `wp_options`, the check silently skipped it because `is_array( $instances )` evaluated to `false`.
  - Impact: Corrupted widget configuration rows are omitted from the integrity report rather than flagged for repair.
  - Fix: Added checks for `! is_array( $sidebars )` and `! is_array( $instances )` emitting `AuditFinding::warning` alerting the user that the option contains corrupt/malformed serialized data and must be rebuilt.
  - Source refs: `src/Audit/Checks/MenuWidgetIntegrityCheck.php:127-135, 179-195`.

## Unified Action Checklist — Reusable Migration Extractions (PLAN.md)

- CR-306 — Status: Pending · Priority: HIGH (PLAN.md §7.2 — Phase 4/5)
  - Finding: `MediaFileCheck` implements attachment post querying, disk path resolution via `UploadsPathResolver`, content fingerprinting via `FileHasher`, basename collision detection, and renaming calculations (`{basename}_site{old_blog_id}.{ext}`). `MediaMigrator.php` (PLAN.md §7.2) requires this exact discovery, dedup, and collision renaming logic for both `--move-media-only` and full migration.
  - Impact: Duplication of critical filesystem and database attachment mapping logic between the audit checker and migration executor.
  - Source refs: `src/Audit/Checks/MediaFileCheck.php:60-91, 176-232`.
  - Fix: Extract `src/Migration/MediaInventory.php` (collecting attachment rows and disk paths) and `src/Migration/MediaCollisionPlan.php` (grouping by hash/basename, resolving `{basename}_site{blog_id}.{ext}` rename targets). Share both between `MediaFileCheck` and `MediaMigrator`.

- CR-307 — Status: Done · Priority: HIGH (PLAN.md §5 — Global Remapping)
  - Finding: PLAN.md §5 mandates a central `SerializedDataRewriter` to safely unserialize (`allowed_classes => ['stdClass']`), recursively walk data structures, remap IDs via `IdMap` and URLs via domain mappings, and recalculate byte-length prefixes (`s:length:"value"`).
  - Impact: Hand-rolled string search-and-replace corrupts PHP serialized strings. Required by `PostMigrator` (postmeta like `_thumbnail_id`, ACF arrays, block attributes), `CommentMigrator` (commentmeta), `MenuMigrator` (widget instance options), and `OptionsMigrator` (plugin settings).
  - Source refs: `PLAN.md` §5 (lines 256-261).
  - Fix: Implemented `src/Migration/SerializedDataRewriter.php` (`rewrite()`, `rewriteStrings()`, `rewriteIntegers()`, `rewriteJson()`, `rewriteAny()`) with recursion guards and unit test suite `tests/Unit/Migration/SerializedDataRewriterTest.php`.

- CR-308 — Status: Pending · Priority: MEDIUM (PLAN.md §7.5 — Phase 7/8)
  - Finding: `MenuWidgetIntegrityCheck` and `NavMenuItemDetector` both query `nav_menu_item` posts and `wp_postmeta` (`_menu_item_type`, `_menu_item_object_id`, `_menu_item_menu_item_parent`, `_menu_item_url`) to resolve item titles, taxonomy terms, and link validity. `MenuMigrator` (PLAN.md §7.5) needs the identical queries to migrate `nav_menu` terms, migrate `nav_menu_item` posts, rewrite `_menu_item_object_id` / `_menu_item_menu_item_parent` via `IdMap`, and generate `menus-overview.md`/`.json` tree views.
  - Impact: Redundant SQL queries and duplicate relationship mapping for navigation menus.
  - Source refs: `src/Audit/Checks/MenuWidgetIntegrityCheck.php:35-75`, `src/ContentAudit/Detectors/NavMenuItemDetector.php:40-80`.
  - Fix: Extract `src/Migration/MenuInventory.php` to gather menu terms, menu items, hierarchy, and target relationships in a single reusable model.

- CR-309 — Status: Pending · Priority: MEDIUM (PLAN.md §7.5 — Phase 7/8)
  - Finding: `MenuWidgetIntegrityCheck` parses `sidebars_widgets` and multi-widget option instances (`widget_{type}`). `MenuMigrator` must migrate and namespace `sidebars_widgets` and each `widget_{type}` instance into orphaned/inactive widget areas on destination.
  - Impact: Duplicate parsing of WordPress widget sidebar arrays and multi-widget instance storage structures.
  - Source refs: `src/Audit/Checks/MenuWidgetIntegrityCheck.php:118-185`.
  - Fix: Extract `src/Migration/WidgetInventory.php` to parse and namespace sidebar and widget instance options for both audit and migration.

- CR-310 — Status: Pending · Priority: MEDIUM (PLAN.md §7.1 & §7.6 — Phase 5/9)
  - Finding: `PostScanner` builds URLs using `post_name` alone, losing hierarchical parent paths (`/parent/child/`) for pages and hierarchical CPTs. `PostMigrator` needs two-pass parent-child resolution (`post_parent` rewritten via `IdMap`), and `RedirectMapBuilder` + `UrlRewriter` require exact hierarchical permalinks for 301 redirects and internal link rewriting.
  - Impact: Truncated URLs in audit reports and incorrect redirect map targets for nested pages.
  - Source refs: `src/ContentAudit/PostScanner.php:194`, `src/ContentAudit/ContentAuditRow.php:55`.
  - Fix: Extract `src/Migration/PostHierarchyResolver.php` to build parent-child slug paths for hierarchical post types, used by `PostScanner` (accurate `original_url`), `UrlRewriter`, and `RedirectMapBuilder`.

- CR-311 — Status: Pending · Priority: LOW (PLAN.md §7.1 & §9)
  - Finding: `ContactPageDiscoveryCheck` scans posts for contact page slugs (`contact`, `contact-us`, `contact-me`, `get-in-touch`) and form plugin footprints. `PostMigrator` and `UrlRewriter` must canonicalize contact page slugs to `/contact/` and map all variant URLs into the redirect map.
  - Impact: Risk of divergent contact slug matching rules between audit discovery and migration rewriting.
  - Source refs: `src/Audit/Checks/ContactPageDiscoveryCheck.php:45-75`.
  - Fix: Extract `src/Migration/ContactPageCanonicalizer.php` to share slug detection patterns and canonicalization mapping.

- CR-312 — Status: Pending · Priority: LOW (PLAN.md §7.4 — Phase 6)
  - Finding: `OrphanedMetaCheck` queries `comments` and `commentmeta`. `CommentMigrator` must chunk comments, remap `comment_post_ID` and `user_id` via `IdMap`, resolve threaded replies (`comment_parent`) in a second pass, and copy `commentmeta` using `SerializedDataRewriter`.
  - Impact: Unchunked comments queries risk MySQL prepared statement limits or memory pressure on sites with high comment volumes.
  - Source refs: `src/Audit/Checks/OrphanedMetaCheck.php:50-80`.
  - Fix: Extract `src/Migration/CommentQueryHelper.php` (mirroring `PostQueryHelper`) to handle chunked comment and commentmeta fetching.

- CR-313 — Status: Pending · Priority: MEDIUM (PLAN.md §7.6 — Phase 9)
  - Finding: PLAN.md §7.6 specifies generating 301 redirect maps across 6 formats: `redirects.json`, `redirects.md`, `redirects.csv` (Redirection plugin format), `redirects-yoast.csv` (Yoast SEO format), `redirects.htaccess` (Apache), and `redirects-nginx.conf` (Nginx).
  - Impact: Writing multi-format generation inline inside `migrate.php` reduces testability and couples output generation with migration execution.
  - Source refs: `PLAN.md` §7.6 (lines 591-608).
  - Fix: Implement `src/Migration/RedirectMapBuilder.php` as a standalone, pure-logic class with full unit test coverage across all 6 formats.

- CR-314 — Status: Pending · Priority: LOW (PLAN.md §7.5 lines 468-471)
  - Finding: Block-era menus (`wp_navigation`) and template parts embed menu references as block attributes: `<!-- wp:navigation {"ref":123} -->`. In template parts, `wp_navigation` posts, and pages, these `ref` IDs point to other post IDs that must be rewritten via `IdMap`.
  - Impact: Block navigation menus point to dead post IDs post-migration if block attribute JSON is not parsed and remapped.
  - Source refs: `PLAN.md` §7.5 (lines 468-471), `src/ContentAudit/Detectors/BlockDetector.php`.
  - Fix: Extract `src/Migration/BlockAttributeRewriter.php` to scan and remap embedded block JSON attribute IDs (`{"ref":123}`) via `IdMap` for both `PostMigrator` and `MenuMigrator`.

- CR-315 — Status: Pending · Priority: LOW (PLAN.md §7.1 lines 320-322)
  - Finding: In WordPress, post GUIDs must reflect the site and post ID format (`https://destination-url/?p=123`). Both `PostMigrator` and `MediaMigrator` must regenerate GUIDs consistently for all migrated post types.
  - Impact: Inconsistent or non-standard GUID generation across migrated post and attachment rows.
  - Source refs: `PLAN.md` §7.1 (lines 320-322).
  - Fix: Create `src/Migration/GuidGenerator.php` (`GuidGenerator::forPost(string $destinationUrl, int $newPostId): string`).

## Future Enhancements (PLAN.md §12.5)

- CR-304 — Status: Pending · Priority: LOW (Future Enhancement)
  - Finding: In `DivergentSiteOptionCheck`, `EXCLUDED_OPTION_NAMES` is a hardcoded PHP constant. As more plugins are audited across different multisite networks, users cannot suppress known-benign option differences without modifying core PHP code.
  - Impact: Noise in divergent options reporting for sites running specialized plugins.
  - What remains: Allow user-configured suppressions/whitelists in `config/option-keys.php` or a dedicated `config/divergent-options.php` that augments the built-in exclusions. Added to `PLAN.md` §12.5.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:61-113`, `PLAN.md` §12.5.

- CR-305 — Status: Pending · Priority: LOW (Future Enhancement)
  - Finding: Serialized options frequently diverge across subsites solely because embedded URLs differ (e.g., `http://site1.example.com` vs `http://site2.example.com`), while all actual plugin configuration toggles are identical.
  - Impact: Generates false divergence warnings for plugins whose configuration is otherwise uniform across subsites.
  - What remains: Normalize subsite domain URLs before comparing serialized option strings once `SerializedDataRewriter` (CR-307 / CR-204) is implemented. Added to `PLAN.md` §12.5.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:174-189`, `PLAN.md` §12.5.

- CR-316 — Status: Pending · Priority: LOW (Future Enhancement)
  - Finding: In `site-audit.xlsx`, multiple blocks on a post appear semicolon-separated in a single `blocks` cell. In spreadsheet tools (LibreOffice Calc, Excel), filtering on that column shows all combinatorial permutations rather than individual unique blocks.
  - Impact: Hard to see the full list of distinct blocks used across the network or identify which posts contain a specific block without complex substring filtering.
  - What remains: Add a second worksheet tab (`block-inventory`) to `ContentAuditReportWriter::toXlsx()` with columns for `Block (Namespace/Name)`, `Owning Plugin`, `Plugin Slug`, `Occurrences`, and `Sample URLs`. Plug-in resolution driven by `signal_map` in `config/plugin-roles.php`. Added to `PLAN.md` §12.5.
  - Source refs: `src/Report/ContentAuditReportWriter.php:450-480`, `PLAN.md` §12.5.

## Design Limitations

- **Single-Site Destination Only**:
  The suite is explicitly designed to merge WordPress multisite networks into a single-site WordPress destination. It does not support multisite-to-multisite consolidation. All destination schema modifications, admin ID renumbering (`AdminIdRenumberer`), and data migrations target single-site table layouts (`%susers`, `%sposts`, `%scomments`, `%soptions`). Documented in `PLAN.md` §2 and `README.md`.

## Positive Observations (N/A)

- Keyset-chunked querying in `MalwareIndicatorCheck` (`ID > :last_id ORDER BY ID ASC LIMIT 500`) reliably bounds memory usage regardless of subsite size.
- Hoisting filesystem discovery in `PluginDataCheck` outside the subsite loop eliminates repetitive disk I/O on networks with dozens of sites.
- Regex table filtering in `PodsDetectionCheck` (`/(?:^|_)pods(?:rel|meta|_|$)/i`) precisely avoids false positives on unrelated tables like `wp_podcasts`.
- Transaction boundary split in `AdminIdRenumberer` prevents MySQL DDL implicit commits from breaking DML rollback.
