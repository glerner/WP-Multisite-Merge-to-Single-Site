# Code Review — 2026-10-03

## Summary

- Scope: Test infrastructure for the DB-bound classes in `src/Migration/` and
  `src/Db/`, plus promotion of three "Future Enhancements" from the earlier
  `code-review-2026-09-28.md` review into completed work.
- Date: 2026-10-03 (CR-401 resolved 2026-10-05)
- Status counts: 4 Done (CR-401, CR-402, CR-403, CR-404),
  4 Future ideas (CR-405, CR-406, CR-407, CR-408)

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

- CR-408 — Status: Future idea · Priority: LOW
  (added 2026-10-12)
  - **Idea.** Filter out *unedited default pages* during the merge — pages
    whose content is still the stock boilerplate every install generates.
    Observed on the destination after merging: 8 Cart pages, 9 Checkout
    pages, 9 Sample Pages, 8 My Account pages, 6+ About pages — one copy
    per source subsite, all ending up in the front-end menu. Keeping
    multiple genuinely-different About pages is fine (better than guessing
    which one to keep), but a page that was never edited carries no
    content and is pure menu noise.
  - **Detection.** A page counts as "default" when its `post_content`
    still matches the stock text: WP core's sample page ("This is an
    example of a WordPress page…"), or plugin-generated stub pages whose
    entire body is a single shortcode — `[woocommerce_cart]`,
    `[woocommerce_checkout]`, `[woocommerce_my_account]`. Any edit at all
    escapes the filter.
  - **Keep-one rule.** Only drop a default when a non-default sibling
    exists in the same group; if every copy is a default, keep one so the
    feature still has its page. For WooCommerce, prefer keeping the page
    the destination's `woocommerce_cart_page_id` /
    `woocommerce_checkout_page_id` / `woocommerce_myaccount_page_id` /
    `woocommerce_shop_page_id` options reference — dropping the referenced
    page breaks the storefront even when the row is a duplicate. Menu
    items are `nav_menu_item` posts pointing at the page via
    `_menu_item_object_id`, so each dropped page also needs a decision on
    its menu items: drop them, or repoint them at the kept copy.
  - **Where it fits.** A needs-review group in `PostMigrator`, or a
    post-merge audit check that reports the duplicates rather than
    silently deleting — consistent with "never silently drop findings".

## Positive Observations

- All DB-bound code routes through the single `Connection` wrapper, so the
  one `forTesting()` injection seam unlocked testing for every consumer at
  once — no per-class refactoring was needed.
- The shared read-side SQL in `src/Migration/` was written without
  MySQL-only syntax (no backticks, no `information_schema`, no engine
  clauses), so SQLite fixtures exercise it unmodified. The only dialect
  problems found were `MigrationTable`'s DDL/`INSERT IGNORE` write path and
  the `WidgetInventory` LIKE-escape bug — both now fixed.

---

## Second pass — 2026-10-11 (commit 892edd3)

## Summary

- Scope: Commit 892edd3 ("Phase 4-5 fixes from real-data runs, destination
  baseline snapshot/reset tool, media-filename audit, and author_mapping") —
  reviewed for security, unclear variable/function names, and code that could
  hide errors. Follows the review template in
  wpwm-color-palette-generator/docs/code-review-prompt-template.md; the
  WordPress-multisite and PHP checklists apply.
- Date: 2026-10-11.
- Status counts: 0 Done, 9 Pending (CR-501…CR-509), 0 In Progress,
  0 Deferred, N/A (Positive Observations).

## Decisions / Constraints

- Project conventions (AGENTS.md): named arguments for any call with more
  than ~4 parameters, or any call where same-type arguments could silently
  swap (IDs, booleans, strings); `array(...)` syntax; docblocks on public
  methods; verify with `composer test && composer phpcs && composer phpstan`.
- CLI tools run outside WordPress; SQL is parameterized everywhere; reports
  and state live under `var/`.
- Migration and reset writes touch only `{prefix}*` tables; shell commands
  built by `DestinationReset` escape every argument and pass the password via
  `MYSQL_PWD`, never `--password`.

## Confirmed Meanings in Code

- site category = the per-site top-level category; IdMap key
  `site_category` holds its destination `term_taxonomy_id`. Every post gets
  it plus its original categories/tags, deduped on destination tt id
  (`seen` set inside `PostMigrator::writeRelationships()`).
- `regenerate_ids` (media report) = destination attachment IDs whose source
  had missing size variants; consumed only for the `wp media regenerate`
  hint in the final log line.
- `aliased` (users report) = source users folded onto a destination user by
  `author_mapping`; the old account is not copied, its ID maps via IdMap to
  the target (destination user_login, or the `'admin'` token =
  `admin_user_id`).
- baseline = `var/state/destination-baseline.sql`, the byte-for-byte
  destination starting state restored by `bin/reset-destination.php`.
- `missing_variants` (attachment flag) = this attachment had at least one
  metadata-referenced size variant absent on source disk (main file present).

## Unified Action Checklist

- **CR-501** — Status: Pending; Priority: MEDIUM.
  Finding: `MediaFilenameReportWriter::appendSheet()` builds a fresh workbook
  with `new Spreadsheet()` and then `createSheet()`, so when no
  divergent-options xlsx exists yet the saved workbook has TWO sheets — an
  empty default "Worksheet" plus "media-filenames". Verified empirically:
  `new Spreadsheet()` + `createSheet()` yields "Worksheet, Worksheet 1".
  Impact: empty stray tab in `integrity-*.xlsx` whenever media-filenames
  findings exist without divergent-site-options findings; inconsistent with
  `DivergentOptionsReportWriter`, which renames the default sheet.
  Source refs: src/Report/MediaFilenameReportWriter.php:120-124.
  What remains: use `$spreadsheet->getActiveSheet()->setTitle(...)` when
  creating fresh (mirroring DivergentOptionsReportWriter:213-219), or
  `removeSheetByIndex(0)` after `createSheet()`.
- **CR-502** — Status: Pending; Priority: MEDIUM.
  Finding: `bin/reset-destination.php --snapshot` warns when
  `{prefix}merge_migration_map` exists but still truncates and overwrites
  the baseline file. Impact: one accidental `--snapshot` after a migrate
  run destroys the only known-good baseline (no backup), and every later
  reset silently restores migrated state — the "fresh install" guarantee is
  lost without any error. Source refs: bin/reset-destination.php:78-87.
  What remains: refuse to overwrite an existing baseline when the migration
  map table is present unless `--yes`/an explicit `--force` is passed.
- **CR-503** — Status: Pending; Priority: MEDIUM.
  Finding: `PostMigrator::writeRelationships()` is called with 8 positional
  arguments, and the signature places adjacent same-type ints —
  `$blogId` (int) then `$siteCategoryTtId` (?int). Impact: a transposed pair
  compiles clean and silently mis-tags relationships — the exact class of
  bug that bit this project on site 28, and what the project's named-args
  convention exists to prevent. Source refs:
  src/Migration/PostMigrator.php:242 (call), :613-621 (signature).
  What remains: use named arguments at the call site.
- **CR-504** — Status: Pending; Priority: MEDIUM.
  Finding: `MediaFilenameSuggester::suggest()` is called with 4 positional
  strings of the same type (filename, alt, caption, title). Impact: silent
  swap risk — e.g. alt text treated as the filename — producing wrong
  suggested names in the report with no error. Source refs:
  src/Audit/Checks/MediaFilenameCheck.php:102; signature
  src/Migration/MediaFilenameSuggester.php:1235.
  What remains: named arguments at the call site.
- **CR-505** — Status: Pending; Priority: LOW.
  Finding: `fetchAttachmentContext()` and `usedAsFeaturedImage()` each take
  two adjacent string table names (`$postsTable`, `$postMetaTable`) and are
  called positionally. Impact: posts/postmeta swap would query the wrong
  table; same-type-string risk per convention. Source refs:
  src/Audit/Checks/MediaFilenameCheck.php:92, :124 (calls), :142, :182
  (signatures). What remains: named arguments.
- **CR-506** — Status: Pending; Priority: LOW.
  Finding: `$attachment['missing_variants']` is set only on the main gather
  path (MediaMigrator.php:191); the early paths (no `_wp_attached_file`,
  main file missing, :143-157) leave it unset, and the consumer guards with
  `?? false` at :314. Impact: safe today, but the shape inconsistency means
  a future refactor that drops the `??` gets an undefined-array-key error
  or a silently wrong regenerate list. What remains: initialize
  `'missing_variants' => false` in the attachment array literal so every
  path carries the flag.
- **CR-507** — Status: Pending; Priority: LOW.
  Finding: on a filename containing invalid UTF-8 bytes,
  `preg_match('/[^\x00-\x7F]/u', ...)` returns `false` (not 0), silently
  skipping the non-ASCII check, and `mb_strlen()` counts invalid bytes as
  characters (verified: no exception, byte count returned). Impact:
  corrupt-encoding filenames evade the non-ASCII flag and the 90-char cap is
  byte-based for them — rare, cosmetic. Source refs:
  src/Migration/MediaFilenameSuggester.php:1173-1175, :1217.
  What remains: guard with `mb_check_encoding()` or drop `/u` and count
  bytes deliberately; document the choice.
- **CR-508** — Status: Pending; Priority: LOW.
  Finding: `author_mapping` keys match `user_login` exactly
  (UserMigrator.php:145), and an unresolvable target (login not on the
  destination, or no `admin_user_id`) only logs a warning (:152) that never
  reaches the run report. Impact: a typo'd or differently-cased key silently
  migrates the account normally — correct fallback, but invisible in the
  report's `aliased`/`conflicts` counts, so the user can't tell why the
  mapping didn't apply. What remains: document exact-match/case-sensitive
  semantics in config.sample.php; optionally surface unresolved targets in
  the users report section.
- **CR-509** — Status: Pending; Priority: LOW.
  Finding: `MediaFilenameSuggester::suggest()` returns a `from` field
  (`'filename'|'alt'|'caption'|'title'`), surfaced as the spreadsheet column
  `suggested_from`. Impact: readability only — `from` is terse and the
  column reads awkwardly. Source refs:
  src/Migration/MediaFilenameSuggester.php:1263;
  src/Report/MediaFilenameReportWriter.php:1633. What remains: rename to
  `source` (column `suggested_source`) or keep and document.

## Positive Observations (N/A)

- Shell-command safety is consistent: `DestinationReset` escapes every
  argument with `escapeshellarg()` (including DB-derived table names) and
  carries the password via `MYSQL_PWD`, never `--password` on the command
  line (src/Destination/DestinationReset.php:57-75, :1066-1076). The
  command string itself is never logged.
- The zero-date fix mirrors wpdb's own session behavior and deliberately
  keeps strict modes, so real data problems still surface as errors rather
  than silent truncation (src/Db/Connection.php:30-43, :105-109).
- `MediaFilenameCheck`'s two-phase query loads title/caption/alt only for
  flagged attachments (src/Audit/Checks/MediaFilenameCheck.php:61-90) —
  clean attachments never pay for their full text.
- The site-category dedup in `writeRelationships` counts the assignment even
  when the insert is skipped, keeping the report honest and the outcome
  correct (src/Migration/PostMigrator.php:638-644, documented in the
  docblock).
- `author_mapping` has no data-loss path: an unresolvable target warns and
  migrates the account normally (src/Migration/UserMigrator.php:151-165).
- `bin/reset-destination.php` fails fast on a missing baseline BEFORE
  dropping any tables (bin/reset-destination.php:96-101) — destructive
  paths are guarded.
- New tests mirror production data shapes: network usermeta with serialized
  capabilities, destination-prefix role keys, and map-table assertions
  (tests/Integration/UserMigratorTest.php).
