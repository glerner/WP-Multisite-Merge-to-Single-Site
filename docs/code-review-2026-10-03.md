# Code Review — 2026-10-03

## Summary

- Scope: Test infrastructure for DB-bound classes; promotion of the three 2026-09-28 Future Enhancements to current work.
- Date: 2026-10-03
- Status counts: 3 Done (CR-402, CR-403, CR-404), 1 Pending (CR-401),
  2 Future ideas (CR-405, CR-406)

## Decisions / Constraints

- Unit tests must never connect to the configured Source or Destination
  databases. A test that needs SQL must run against an in-memory or
  throwaway database it creates and owns.
- Reuse over rewrite: shared `src/Migration/` helpers should be validated
  by running their real SQL, not by mocking row results — a fake that
  returns canned rows cannot catch SQL syntax errors, bad JOINs, or
  incorrect chunking logic.

## Confirmed Meanings in Code

- `Connection` (`src/Db/Connection.php`) is `final`, wraps a lazily
  built `PDO`, and hardcodes a `mysql:` DSN in `pdo()`. Its public
  surface used by inventory/migration classes is `siteTable()`,
  `networkTable()`, `fetchAll()`, `fetchOne()`, `fetchScalar()`,
  `execute()`, `lastInsertId()`, and transaction methods.
- `Connection` cannot be PHPUnit-mocked (`final`) and its `?PDO $pdo`
  is private with no injection point, so today there is no way to
  exercise any `Connection` consumer in a unit test.
- `tableExists()` queries `information_schema.TABLES`, which is
  MySQL-specific; SQLite has no `information_schema` (uses
  `sqlite_master`).

## Unified Action Checklist

- CR-401 — Status: Pending · Priority: MEDIUM
  - Finding: DB-bound shared classes have no unit-test coverage because
    `Connection` is `final`, PDO-uninjectable, and MySQL-DSN-only. The
    immediate beneficiaries would be the newest `src/Migration/`
    extractions — `MediaInventory::collect()`/`fingerprints()`,
    `MenuInventory::collect()`, `WidgetInventory::collect()`,
    `CommentQueryHelper::commentBatches()`/`fetchMetaForComments()`,
    `PostQueryHelper::fetchMetaForPosts()` — and later `TermInventory`,
    `UserMigrator`, `TermMigrator`, and the audit `Check`s that now
    delegate to them. Their SQL (JOINs, a correlated subquery in
    `MenuInventory`, `IN()` chunking, keyset `LIMIT` pagination) is
    real logic that static analysis cannot prove correct.
  - Impact: Query bugs in migration-shared code can only be discovered
    by running it against a live multisite — exactly the wrong place to
    find them. Also a safety boundary issue: any attempt to test these
    classes today would require pointing a test at a real database.
  - Source refs: `src/Db/Connection.php:18-68` (`final`, private `?PDO`,
    hardcoded `mysql:` DSN), `src/Db/Connection.php:263-274`
    (`information_schema`-dependent `tableExists()`).
  - What remains:
    1. Add a test-only PDO injection seam on `Connection`, e.g.
       `Connection::forTesting( PDO $pdo )` or `::sqlite()` (keeps the
       class `final`; the factory skips the `mysql:` DSN path).
       `siteTable()`/`networkTable()` are pure string helpers off
       `DatabaseConfig` and need no real server.
    2. Add `tests/Support/` fixture helpers that build minimal
       WordPress-shaped schemas (`wp_posts`, `wp_postmeta`, `wp_terms`,
       `wp_term_taxonomy`, `wp_term_relationships`, `wp_options`,
       `wp_comments`, `wp_commentmeta`, plus `{prefix}{blogId}_*`
       multisite variants) on `new PDO('sqlite::memory:')` and seed
       production-shaped rows (postmeta as `meta_key => value[]`,
       serialized options).
    3. The plain `SELECT`/`JOIN`/`IN`/`LIMIT`/`subquery` SQL in the
       read-side classes above is already SQLite-compatible — verify
       each new test rather than assuming. The write-side is NOT:
       `MigrationTable` uses `ENGINE=InnoDB` DDL and `INSERT IGNORE`
       (`src/Migration/MigrationTable.php:39-47,75`). Testing
       migrators/`IdMap` persistence will need a driver-aware
       helper (e.g. `Connection::insertIgnore()` emitting `INSERT
       IGNORE` on MySQL / `INSERT OR IGNORE` on SQLite, and
       dialect-split table DDL).
    4. Decide `tableExists()` strategy for SQLite (branch on driver,
       or a `sqlite_master` query) — it is the only `information_schema`
       consumer on `Connection`.
    5. First tests to write: `MediaInventoryTest` (found/missing split
       via `UploadsPathResolver` against a temp dir), `MenuInventoryTest`
       (menus + `_menu_item_*` meta + `nav_menu` taxonomy join),
       `CommentQueryHelperTest` (keyset batching boundaries,
       commentmeta chunking), `PostQueryHelperTest::fetchMetaForPosts`
       coverage. Phase 4's `MediaMigrator` (2026-10-05) adds its
       end-to-end DB + filesystem paths here too — its pure planning
       (`planTargets`, `rewriteAttachmentMetadata`, `variantBasenames`,
       `collisionAlternatives`) is already unit-tested; the insert/copy
       execution paths are the first integration-test candidates.

- CR-402 — Status: Done · Priority: LOW (moved from 2026-09-28 CR-304)
  - Finding: In `DivergentSiteOptionCheck`, `EXCLUDED_OPTION_NAMES` is a
    hardcoded PHP constant. As more plugins are audited across different
    multisite networks, users cannot suppress known-benign option
    differences without modifying core PHP code.
  - Impact: Noise in divergent options reporting for sites running
    specialized plugins.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:61-113`,
    `PLAN.md` §12.5.
  - Fix: Added optional `config/divergent-options.php` (sample
    `config/divergent-options.sample.php`) returning extra option-name
    exclusions; `ConfigLoader` loads it into
    `MergeConfig::$divergentOptionExclusions`; the check merges them
    with the built-in list before comparing. Covered by
    `ConfigLoaderTest` (optional-by-default, list loading).

- CR-403 — Status: Done · Priority: LOW (moved from 2026-09-28 CR-305)
  - Finding: Serialized options frequently diverge across subsites solely
    because embedded URLs differ (e.g. `http://site1.example.com` vs
    `http://site2.example.com`), while all actual plugin configuration
    toggles are identical.
  - Impact: Generates false divergence warnings for plugins whose
    configuration is otherwise uniform across subsites.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:174-189`,
    `PLAN.md` §12.5.
  - Fix: The check now normalizes each site's own home URL (from its
    `home`/`siteurl` option) to a `__MERGE_SITE_HOME__` placeholder
    before grouping values. Serialized values go through
    `SerializedDataRewriter::rewriteStrings()` so PHP string-length
    prefixes stay correct; grouping then uses the normalized value while
    findings display the first site's real string per group. Differences
    that disappear after normalization are no longer reported as
    conflicts.

- CR-404 — Status: Done · Priority: LOW (moved from 2026-09-28 CR-316)
  - Finding: In `site-audit.xlsx`, multiple blocks on a post appear
    semicolon-separated in a single `blocks` cell. In spreadsheet tools
    (LibreOffice Calc, Excel), filtering on that column shows all
    combinatorial permutations rather than individual unique blocks.
  - Impact: Hard to see the full list of distinct blocks used across the
    network or identify which posts contain a specific block without
    complex substring filtering.
  - Source refs: `src/Report/ContentAuditReportWriter.php:450-480`,
    `config/plugin-roles.php` (`signal_map`), `PLAN.md` §12.5.
  - Fix: Added a second `block-inventory` worksheet tab to
    `ContentAuditReportWriter::toXlsx()` (always created, stable across
    runs) with columns `Block (Namespace/Name)`, `Owning Plugin`,
    `Plugin Slug`, `Occurrences`, and `Used On URL` -- one row per
    distinct (block, page) pair, so filtering the URL column shows
    every block on a page and filtering the block column shows every
    page (URL) that uses a given block (`Occurrences` repeats the
    block's page total).
    Block owners resolve from `PluginUsageRollup::build()` output --
    i.e. the `signal_map` from `config/plugin-roles.php` -- via the
    rollup's `blocks|{label}` signals on used/not_installed entities;
    unmapped namespaces get empty owner columns (a signal to map them).
    Pure aggregation lives in public `blockInventory()`; `write()` passes
    `$pluginUsage` through to `toXlsx()`. Covered by two tests in
    `ContentAuditReportWriterTest` (per-usage rows + unmapped blocks).
    On very large networks the tab can reach thousands of rows --
    spreadsheet row limits (1M+) are not the binding constraint, but
    run `--site=<blog_id>` if only one subsite's picture is needed.

## Future Ideas

- CR-405 — Status: Future idea · Priority: LOW
  - Idea: Enrich `media-files.duplicate-content-different-name`
    findings with the metadata that makes manual dedupe decisions
    possible: each duplicate file's alt text, caption, and the pages
    that actually display it. A "George0085-crop300.png vs
    George0085-crop3001.png" row is only actionable once you can see
    which copy carries real alt text and which posts reference each.
  - Feasibility (checked 2026-10-05, no code written): the pieces
    mostly exist. `MediaInventory::collect()` already returns
    `post_id` for every found file (the finding just doesn't emit it);
    alt text is `_wp_attachment_image_alt` postmeta and caption is the
    attachment post's `post_excerpt`, both fetchable via the existing
    `PostQueryHelper::fetchMetaForPosts()` chunked-meta helper. The
    genuinely new query is "which posts display this attachment":
    `_thumbnail_id` postmeta (featured images) plus `post_content`
    LIKE scans for the image URL, the `wp-image-{id}` class, and
    `[gallery ids=...]`/`wp:{"id":N}` references. Bounded to the
    duplicate groups only, so the cost stays small; alternatively the
    site-audit content-scan pipeline already walks every post's
    content and could cross-reference.

- CR-406 — Status: Future idea · Priority: LOW
  - Idea: Run `bin/site-audit.php` against a plain single-site
    WordPress install — i.e. repackage the audit as a standalone
    "WP Site Audit" product, differing mainly in docs and config
    shape rather than code.
  - Feasibility (checked 2026-10-05, no code written): the scan
    engine is already single-site-safe. `siteTable( 'posts', 1 )`
    yields `wp_posts` — the same table name a single install uses —
    and `UploadsPathResolver` resolves blogId 1 to the bare uploads
    path, so every per-site query and path lookup works unchanged if
    fed one synthetic `Site{blogId:1}`. What breaks is confined to the
    multisite plumbing: `SiteSelector` queries `wp_blogs` (absent on
    single-site) and `PluginInventory::networkActiveSlugs()` reads
    `wp_sitemeta` (also absent). `Connection::tableExists()` already
    exists, so detection is cheap.
  - What a "WP Site Audit" re-packaging needs: (a) a synthetic-site
    substitute for `SiteSelector` — detect a missing `wp_blogs` table
    and fabricate `Site{1}` from the `siteurl`/`home` options; (b)
    skip `networkActiveSlugs()` when `wp_sitemeta` is absent
    (single-site has no network-active plugins); (c) relax config
    requirements for audit-only runs — `sites.php` and the
    `destination` DB section are meaningless there (destination_url
    only feeds the needs-review "guessed URL" column, so it can be
    optional); (d) documentation: a standalone README that drops the
    merge/multisite framing. The detectors, report writers (CSV /
    JSON / XLSX / summary), and needs-review pipeline need no changes.

## Positive Observations (N/A)

- All current DB-bound code routes through the single `Connection`
  wrapper, so one injection seam unlocks testing for every consumer —
  no per-class refactoring needed.
- The shared read-side SQL in `src/Migration/` was written without
  MySQL-only syntax (no backticks, `information_schema`, or engine
  clauses), so SQLite fixtures can exercise it unmodified; only the
  `MigrationTable`/`TermMigrator` write-path has MySQL-isms.
