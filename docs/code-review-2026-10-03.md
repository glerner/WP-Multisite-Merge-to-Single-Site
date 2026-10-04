# Code Review — 2026-10-03

## Summary

- Scope: Test infrastructure for DB-bound classes; promotion of the three 2026-09-28 Future Enhancements to current work.
- Date: 2026-10-03
- Status counts: 0 Done, 4 Pending

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
       coverage.

- CR-402 — Status: Pending · Priority: LOW (moved from 2026-09-28 CR-304)
  - Finding: In `DivergentSiteOptionCheck`, `EXCLUDED_OPTION_NAMES` is a
    hardcoded PHP constant. As more plugins are audited across different
    multisite networks, users cannot suppress known-benign option
    differences without modifying core PHP code.
  - Impact: Noise in divergent options reporting for sites running
    specialized plugins.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:61-113`,
    `PLAN.md` §12.5.
  - What remains: Allow user-configured suppressions/whitelists in
    `config/option-keys.php` or a dedicated `config/divergent-options.php`
    that augments the built-in exclusions.

- CR-403 — Status: Pending · Priority: LOW (moved from 2026-09-28 CR-305)
  - Finding: Serialized options frequently diverge across subsites solely
    because embedded URLs differ (e.g. `http://site1.example.com` vs
    `http://site2.example.com`), while all actual plugin configuration
    toggles are identical.
  - Impact: Generates false divergence warnings for plugins whose
    configuration is otherwise uniform across subsites.
  - Source refs: `src/Audit/Checks/DivergentSiteOptionCheck.php:174-189`,
    `PLAN.md` §12.5.
  - What remains: Normalize subsite domain URLs before comparing
    serialized option strings. Now unblocked — `SerializedDataRewriter`
    (CR-307 / CR-204) is implemented; use its string-rewriting walk to
    substitute each site's home URL with a placeholder before comparison.

- CR-404 — Status: Pending · Priority: LOW (moved from 2026-09-28 CR-316)
  - Finding: In `site-audit.xlsx`, multiple blocks on a post appear
    semicolon-separated in a single `blocks` cell. In spreadsheet tools
    (LibreOffice Calc, Excel), filtering on that column shows all
    combinatorial permutations rather than individual unique blocks.
  - Impact: Hard to see the full list of distinct blocks used across the
    network or identify which posts contain a specific block without
    complex substring filtering.
  - Source refs: `src/Report/ContentAuditReportWriter.php:450-480`,
    `config/plugin-roles.php` (`signal_map`), `PLAN.md` §12.5.
  - What remains: Add a second worksheet tab (`block-inventory`) to
    `ContentAuditReportWriter::toXlsx()` with columns for
    `Block (Namespace/Name)`, `Owning Plugin`, `Plugin Slug`,
    `Occurrences`, and `Sample URLs`. Plugin resolution driven by
    `signal_map` in `config/plugin-roles.php`.

## Positive Observations (N/A)

- All current DB-bound code routes through the single `Connection`
  wrapper, so one injection seam unlocks testing for every consumer —
  no per-class refactoring needed.
- The shared read-side SQL in `src/Migration/` was written without
  MySQL-only syntax (no backticks, `information_schema`, or engine
  clauses), so SQLite fixtures can exercise it unmodified; only the
  `MigrationTable`/`TermMigrator` write-path has MySQL-isms.
