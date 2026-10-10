# Code Review — 2026-10-03

## Summary

- Scope: Test infrastructure for the DB-bound classes in `src/Migration/` and
  `src/Db/`, plus promotion of three "Future Enhancements" from the earlier
  `code-review-2026-09-28.md` review into completed work.
- Date: 2026-10-03 (CR-401 resolved 2026-10-05)
- Status counts: 4 Done (CR-401, CR-402, CR-403, CR-404),
  3 Future ideas (CR-405, CR-406, CR-407)

## Decisions / Constraints

- Unit and integration tests must never connect to the configured Source or
  Destination databases. A test that needs SQL must run against an in-memory
  or throwaway database the test creates and owns.
- Reuse over rewrite: the shared `src/Migration/` helpers are validated by
  running their real SQL, not by mocking row results. A fake that returns
  canned rows cannot catch SQL syntax errors, bad JOINs, or off-by-one bugs
  in batching logic — the whole point of this review item.

## Confirmed Meanings in Code

- `Connection` (`src/Db/Connection.php`) is `final` and wraps a `PDO` that it
  builds lazily from a hardcoded `mysql:` DSN. Its public surface — what
  every inventory/migration class calls — is `siteTable()`,
  `networkTable()`, `fetchAll()`, `fetchOne()`, `fetchScalar()`,
  `execute()`, `lastInsertId()`, and the transaction methods.
- Being `final` means PHPUnit cannot mock `Connection`, and before this
  review was acted on its `?PDO $pdo` was private with no injection point —
  so there was literally no way to exercise any `Connection` consumer in a
  test.
- `tableExists()` queried `information_schema.TABLES`, which exists only in
  MySQL. SQLite's equivalent catalog table is `sqlite_master`.

## Unified Action Checklist

- CR-401 — Status: Done (2026-10-05) · Priority: MEDIUM
  - **Finding.** The newest shared migration classes ran real SQL — JOINs, a
    correlated subquery in `MenuInventory`, chunked `IN()` lists, paginated
    batch reads — but had zero test coverage, and structurally *couldn't*
    have any: every one of them goes through `Db\Connection`, which was
    `final` (unmockable), had no PDO injection point, and always connected
    via a MySQL DSN. The affected classes were `MediaInventory::collect()` /
    `fingerprints()`, `MenuInventory::collect()`, `WidgetInventory::collect()`,
    `CommentQueryHelper::commentBatches()` / `fetchMetaForComments()`,
    `PostQueryHelper::fetchMetaForPosts()`, `MigrationTable`, and later
    `MediaMigrator`'s end-to-end paths — plus everything that delegates to
    them (`TermInventory`, `UserMigrator`, `TermMigrator`, audit `Check`s).
  - **Why it mattered.** The only way to discover a query bug in that code
    was to run it against the live multisite — the worst possible place to
    find it. Worse, any attempt to test these classes would have required
    pointing a test at a real configured database, violating the safety
    boundary above.
  - **What was done (2026-10-05).**
    1. `Connection::forTesting( PDO $pdo )` — a named constructor that wraps
       an already-connected PDO, skipping the `mysql:` DSN path entirely.
       The class stays `final` and production construction is unchanged.
       `siteTable()`/`networkTable()` are pure string helpers built from
       `DatabaseConfig`, so a SQLite PDO works for every consumer.
    2. Driver-awareness added only where the SQL dialects genuinely differ:
       `driverName()`; `tableExists()` now queries `information_schema` on
       MySQL and `sqlite_master` on SQLite; new `insertIgnore()` emits
       `INSERT IGNORE` on MySQL and `INSERT OR IGNORE` on SQLite.
    3. `MigrationTable` splits its `CREATE TABLE` per driver — the SQLite
       branch drops `ENGINE=InnoDB` and the inline `KEY` clauses (SQLite
       requires indexes as separate `CREATE INDEX` statements) — and
       `record()` now goes through `insertIgnore()`.
    4. `tests/Support/WpTestSchema` — a fixture builder that creates minimal
       WordPress-shaped tables (`wp_posts`, `wp_postmeta`, `wp_terms`,
       `wp_term_taxonomy`, `wp_term_relationships`, `wp_options`,
       `wp_comments`, `wp_commentmeta`, the multisite `{prefix}{blogId}_*`
       variants, and the network `users`/`usermeta`/`blogs`/`sitemeta`) on a
       `sqlite::memory:` PDO, plus row-insert helpers that accept
       production-shaped data (postmeta as `meta_key => array of values`,
       options possibly serialized).
    5. `tests/Support/SqliteTestCase` — a PHPUnit base class that builds the
       in-memory schema and a `Connection::forTesting()` wrapper per test,
       and marks the test skipped when `pdo_sqlite` isn't installed (so the
       suite still passes on machines without it; `php8.4-sqlite3` provides
       the driver here).
  - **Integration tests added (`tests/Integration/`).** Each drives the
    class's real SQL against the fixture schema:
    - `MediaInventoryTest` — collect `_wp_attached_file` rows, split into
      found/missing against a temp uploads directory, and fingerprint files
      for dedup.
    - `MenuInventoryTest` — `nav_menu` terms joined to `nav_menu_item` posts
      and their `_menu_item_*` meta; covers the correlated subquery that
      resolves which menu each item belongs to.
    - `WidgetInventoryTest` — reads `sidebars_widgets` plus every `widget_%`
      option, including a deliberately corrupt serialized row.
    - `CommentQueryHelperTest` — exercises the two real risks in
      `CommentQueryHelper`: (a) keyset pagination, where batches are fetched
      as `WHERE comment_ID > {last seen ID} ORDER BY comment_ID LIMIT {n}`
      instead of `OFFSET` — the fixture seeds comment IDs with gaps so a
      boundary bug can't hide; and (b) commentmeta fetching, which chunks
      `IN(...)` ID lists (MySQL prepared statements cap at ~65535
      placeholders, so meta is fetched 5000 IDs at a time) — the test seeds
      5005 comments to force a second chunk and prove nothing is dropped.
    - `PostQueryHelperMetaTest` — the same chunked-`IN()` boundary (5005
      posts) plus verification that meta comes back grouped as
      `meta_key => array of values` per post.
    - `MigrationTableTest` — the per-driver `CREATE TABLE`, `prewarm()`
      loading existing mappings into `IdMap`, and `record()` idempotency
      (recording the same mapping twice neither errors nor duplicates).
    - `MediaMigratorEndToEndTest` — the full Phase 4 path: two sites whose
      uploads contain the same filename get collision-renamed to
      `{basename}_site{blog_id}.{ext}` including `-{W}x{H}` thumbnail
      variants; byte-identical files are deduped by hash; `--dry-run`
      writes nothing; a second run skips already-recorded files (resume).
    - `PostMigratorTest` — added later under Phase 5 on the same harness:
      slug dedup, contact canonicalization, `_thumbnail_id`/block-attribute
      remapping, parent fixups, term counts, trash/future, dry-run, resume.
  - **A real bug this harness caught.** `WidgetInventory::collect()` used
    `LIKE 'widget\_%'` to find widget options. MySQL treats backslash as the
    default LIKE escape character, so this worked; SQLite has no default
    escape character, so the pattern would never match. It now uses an
    explicit, portable escape: `LIKE 'widget$_%' ESCAPE '$'`, correct on
    both drivers. This is exactly the class of bug the review item existed
    to surface.

- CR-402 — Status: Done · Priority: LOW
  (carried over from `code-review-2026-09-28.md` CR-304)
  - **Finding.** The integrity checker's `divergent-site-options` check
    compares same-named autoloaded options across subsites and reports the
    ones whose values differ. The list of option names to ignore
    (`EXCLUDED_OPTION_NAMES` — transients, cron, caches, etc.) was a
    hardcoded PHP constant, so suppressing a known-benign difference
    required editing core source.
  - **Why it mattered.** Real sites running specialized plugins produce
    benign divergences that flood the report; the fix had to be
    configuration, not a code change per plugin.
  - **Fix.** An optional `config/divergent-options.php` (committed sample:
    `config/divergent-options.sample.php`) returns extra option-name
    exclusions; `ConfigLoader` loads it into
    `MergeConfig::$divergentOptionExclusions`, and the check merges them
    with the built-in list before comparing. Covered by `ConfigLoaderTest`
    (absent-by-default, list loading).

- CR-403 — Status: Done · Priority: LOW
  (carried over from `code-review-2026-09-28.md` CR-305)
  - **Finding.** Same check as CR-402, different noise source: serialized
    option values frequently embed the site's own URL
    (`s:23:"http://site2.example.com/…"`). Two subsites with byte-for-byte
    identical plugin *settings* still grouped apart, purely because each
    value contained its own domain.
  - **Why it mattered.** Produced false divergence warnings for plugins
    whose configuration was actually uniform — the most common case in this
    network.
  - **Fix.** Before grouping, the check replaces each site's own home URL
    (read from that site's `home`/`siteurl` option) with a
    `__MERGE_SITE_HOME__` placeholder. The replacement runs through
    `SerializedDataRewriter::rewriteStrings()` so PHP `s:N:"..."` string
    lengths stay correct. Grouping then uses the normalized value while the
    report still displays each group's first real value. Differences that
    vanish after normalization are no longer reported as conflicts.

- CR-404 — Status: Done · Priority: LOW
  (carried over from `code-review-2026-09-28.md` CR-316)
  - **Finding.** In `site-audit.xlsx`, every block a post used was joined
    with `;` into a single `blocks` cell. Spreadsheet filters (Excel,
    LibreOffice Calc) treat each distinct cell value as one item, so the
    filter dropdown showed thousands of combinatorial permutations —
    impossible to answer "which pages use `uagb/columns`?".
  - **Why it mattered.** The audit's whole purpose is finding which pages a
    plugin's blocks live on; the report format defeated the basic question.
  - **Fix.** `ContentAuditReportWriter::toXlsx()` gained a second worksheet,
    `block-inventory` (always created so the report shape is stable), with
    columns `Block (Namespace/Name)`, `Owning Plugin`, `Plugin Slug`,
    `Occurrences`, `Used On URL` — one row per distinct (block, page) pair.
    Filtering the block column now lists every page using that block;
    filtering the URL column lists every block on that page; `Occurrences`
    repeats the block's total on that page. Block ownership resolves from
    `PluginUsageRollup::build()` output (the `signal_map` in
    `config/plugin-roles.php`) via the rollup's `blocks|{label}` signals;
    unmapped namespaces get empty owner columns as a "map me" signal. The
    pure aggregation lives in public `blockInventory()`; `write()` passes
    `$pluginUsage` through to `toXlsx()`. Covered by two
    `ContentAuditReportWriterTest` cases (per-usage rows, unmapped blocks).
    On very large networks this tab can reach thousands of rows — still far
    under spreadsheet limits; use `--site=<blog_id>` to scope a run to one
    subsite.

## Future Ideas

- CR-405 — Status: Future idea · Priority: LOW
  - **Idea.** The media findings report lists
    `duplicate-content-different-name` pairs — files that are byte-identical
    but stored under different names (`George0085-crop300.png` vs
    `George0085-crop3001.png`). Today the row tells you the files are
    duplicates but nothing about which copy to keep. The enhancement would
    add, per duplicate: the attachment's alt text (`_wp_attachment_image_alt`
    postmeta), its caption (the attachment post's `post_excerpt`), and the
    posts/pages that actually reference it. With that, a row becomes a
    decision instead of a lookup chore: keep the copy carrying real alt text
    and the references, drop the orphan.
  - **Feasibility (checked 2026-10-05, no code written).** Mostly plumbing,
    not new machinery. `MediaInventory::collect()` already returns
    `post_id` for every found file — the finding just doesn't emit it. Alt
    text and caption are fetchable through the existing chunked-meta helper
    `PostQueryHelper::fetchMetaForPosts()`. The genuinely new query is
    "which posts display this attachment": `_thumbnail_id` postmeta
    (featured images), plus `post_content` scans for the image URL, the
    `wp-image-{id}` CSS class, and `[gallery ids=…]` / `wp:{"id":N}` block
    attributes. Bounded to duplicate groups only, so cost stays small; or
    the site-audit content-scan pipeline, which already walks every post's
    content, could cross-reference.

- CR-406 — Status: Future idea · Priority: LOW
  - **Idea.** Repackage `bin/site-audit.php` so it also runs against a plain
    single-site WordPress install — effectively a standalone "WP Site Audit"
    product. The difference is docs and config shape more than code.
  - **Feasibility (checked 2026-10-05, no code written).** The scan engine
    is already single-site-safe: `siteTable('posts', 1)` yields `wp_posts`,
    the same name a single install uses, and `UploadsPathResolver` resolves
    blog ID 1 to the bare uploads path — so every per-site query works
    unchanged if fed one synthetic `Site{blogId:1}`. What breaks is only the
    multisite plumbing: `SiteSelector` queries `wp_blogs` (absent on
    single-site) and `PluginInventory::networkActiveSlugs()` reads
    `wp_sitemeta` (also absent). `Connection::tableExists()` already exists,
    so detection is cheap.
  - **What a re-packaging needs.** (a) A synthetic-site substitute for
    `SiteSelector`: when `wp_blogs` is missing, fabricate `Site{1}` from the
    `siteurl`/`home` options. (b) Skip `networkActiveSlugs()` when
    `wp_sitemeta` is absent — single-site has no network-active plugins.
    (c) Relax config requirements for audit-only runs: `sites.php` and the
    destination DB section are meaningless there (`destination_url` only
    feeds the needs-review "guessed URL" column, so it can be optional).
    (d) A standalone README that drops the merge/multisite framing. The
    detectors, report writers (CSV/JSON/XLSX/summary), and needs-review
    pipeline need no changes.

- CR-407 — Status: Future idea · Priority: LOW
  (added 2026-10-08)
  - **Idea.** PLAN.md has grown to ~1,000 lines and several sections are
    written as long run-on paragraphs where three or more distinct facts are
    joined with em-dashes and semicolons. When a paragraph contains that
    many separable facts, a nested bullet list (2nd/3rd level) makes each
    fact scannable on its own, and a later edit is far less likely to
    mangle one fact while rewording its neighbor. The plan is the
    reference document for decisions made months ago; the reader — the
    owner, returning later — should be able to extract "what was decided
    about X" in one glance instead of parsing a sentence.
  - **Scope.** Convert multi-fact run-on paragraphs into sub-list items;
    keep prose for narrative and context; change structure, not meaning.
    Sections already flagged as the worst offenders: §7.5's generic-options
    prose, §7.5's menus/widgets paragraphs, and §13's remaining open
    items. The report-conventions section (§9) was converted to nested
    lists on 2026-10-08 as the pilot for the pattern.
  - **How it will be done.** Incrementally, one section per small diff —
    a one-shot full-file rewrite was deliberately rejected (2026-10-08)
    because a ~1,000-line rewrite in a single pass cannot be reviewed and
    risks silently losing detail. Each converted section ships on its own
    and is re-readable immediately.

## Positive Observations

- All DB-bound code routes through the single `Connection` wrapper, so the
  one `forTesting()` injection seam unlocked testing for every consumer at
  once — no per-class refactoring was needed.
- The shared read-side SQL in `src/Migration/` was written without
  MySQL-only syntax (no backticks, no `information_schema`, no engine
  clauses), so SQLite fixtures exercise it unmodified. The only dialect
  problems found were `MigrationTable`'s DDL/`INSERT IGNORE` write path and
  the `WidgetInventory` LIKE-escape bug — both now fixed.
