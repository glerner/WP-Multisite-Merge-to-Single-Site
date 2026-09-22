# WordPress Multisite → Single Site Merge: Project Plan

Status: **Phases 0–1 built and in active use** (integrity checker runs
against the real source DB; 118 PHPUnit tests, PHPCS + PHPStan clean).
`site-audit.php` (Phase 2) also exists.

Location: `~/sites/merge-multisite` (standalone project).

## 1. Goals

1. Let you choose which subsites of an existing WordPress multisite network
   (excluding already-deleted sites, and excluding any sites you explicitly
   opt out of, e.g. `molten-salt-reactor.glerner.com`) get merged into one
   new, single-site WordPress installation.
2. Turn each merged subsite into a top-level Category on the destination
   site, while preserving/merging each subsite's own categories and tags.
3. Migrate posts, pages, custom post types, media/attachments (files +
   metadata), comments, users, menus, widgets, and plugin
   settings/options — rewriting every internal ID (site ID, post ID,
   term ID, user ID, comment ID, menu item ID, attachment ID) so
   everything links correctly in the destination database.
4. Reorganize media from `wp-content/uploads/sites/{blog_id}/YYYY/MM/`
   into the normal single-site `wp-content/uploads/YYYY/MM/` layout,
   de-duplicating files that are byte-identical across sites (e.g. a
   shared logo), and safely renaming files that collide by name but
   differ in content.
5. Provide a read-only **pre-flight integrity checker**
   (`multisite-integrity-checker.php`) that audits the source multisite
   before you run any migration, so problems are fixed at the source,
   not discovered mid-migration.
6. Rewrite every merged subsite's own domain (e.g.
   `android.glerner.com`, `website-tech.glerner.com`) to one canonical
   `destination_url`, and generate a 301 redirect map (old URL → new
   URL) for SEO continuity.
7. Provide a separate **content/plugin-usage audit tool**
   (`site-audit.php`) that scans every page/post of a site (single
   site, one subsite of a multisite, or an entire multisite) and
   reports every non-core block type and every content-plugin footprint
   in use (page builders, form plugins, galleries, video embeds, SEO,
   ecommerce, etc.), exported as CSV/Google-Sheets-importable output —
   so you can decide which duplicate plugins (e.g. multiple contact-form
   plugins) to standardize on before or after migrating.
8. Be reusable on other multisites (not just yours) where different
   subsites may run different plugin sets — the tool should not assume
   uniform plugins, table prefixes, or directory layouts across sites.
9. Professional-quality PHP: WPCS-flavored style, full docblocks,
   PHPUnit tests, PHPCS + PHPStan static analysis, logging, and safe
   re-runs.

## 2. Non-goals / explicit exclusions

- The migration tool writes directly to a **local/staging** destination
  database + filesystem. Getting that verified local site into
  production (new hosting) is **your** existing process (e.g. exporting
  a `.sql` file, or UpdraftPlus with its S3 connection) — not something
  this tool does. This also means Local-environment URLs (e.g.
  `*.lndo.site`) are never something the migrator needs to "fix"; that
  swap is handled by your normal Local↔production migration workflow
  (`wp search-replace`, UpdraftPlus, Local's own "push to live", etc.)
  after this tool's job is done.
- No network activation / plugin installation automation in v1 — you
  will install & activate destination plugins yourself; the migrator
  will warn (not fail) if a plugin's data is found in the source but the
  plugin is not detected as installed on the destination.
- No attempt to consolidate all authors into one user — registered users
  are preserved individually (see §7.3).
- `molten-salt-reactor.glerner.com` (and any other site you flag) is
  excluded via config, not hardcoded.
- Won't touch anything outside `~/sites/merge-multisite`.

## 3. High-level architecture

**Three** standalone CLI tools, sharing a common library, all inside
`~/sites/merge-multisite`:

```
merge-multisite/
├── bin/
│   ├── multisite-integrity-checker.php   # Tool 1: read-only pre-flight audit
│   ├── site-audit.php                    # Tool 2: block/plugin content-usage inventory
│   └── migrate.php                       # Tool 3: the actual migration
├── config/
│   ├── config.sample.php    # DB credentials, paths, options (copy to config.php, gitignored)
│   ├── sites.sample.php     # Site selection: include/exclude list + per-site overrides
│   ├── option-keys.sample.php   # Per-plugin wp_options allowlist (see §7.5)
│   └── term-overrides.sample.php # Optional manual category/tag canonicalization
├── src/
│   ├── Config/               # Config loading & validation
│   ├── Db/                   # PDO connection wrappers (source + destination)
│   ├── Audit/                # multisite-integrity-checker rules (one class per check)
│   ├── ContentAudit/          # site-audit.php: block scanner + plugin-footprint detectors
│   ├── Migration/
│   │   ├── IdMap.php              # Central old-ID -> new-ID registry (all entity types)
│   │   ├── SiteSelector.php       # Reads wp_blogs/wp_site, applies include/exclude + deleted filter
│   │   ├── UserMigrator.php
│   │   ├── TermMigrator.php       # categories + tags, cross-site merge/case rules
│   │   ├── MediaMigrator.php      # files + attachment posts, dedup/rename (also standalone --move-media-only)
│   │   ├── PostMigrator.php       # posts/pages/CPTs + postmeta
│   │   ├── CommentMigrator.php
│   │   ├── MenuMigrator.php       # nav menus + widgets (theme_mods/sidebars_widgets)
│   │   ├── OptionsMigrator.php    # generic + per-plugin option/table handlers
│   │   ├── UrlRewriter.php        # domain collapsing + internal-link rewriting
│   │   └── RedirectMapBuilder.php # multi-format redirect export
│   ├── Plugins/                   # Per-plugin adapters (Yoast SEO, etc.) — pluggable, optional
│   ├── Report/                    # Human-readable + machine-readable (JSON/CSV) report writers
│   └── Support/                   # Logging, batching/pagination, hashing, CLI arg parsing
├── tests/
│   ├── Unit/                     # Pure logic, no DB (ID mapping, term-merge rules, dedup logic)
│   ├── Integration/              # Against throwaway SQLite/MySQL fixture DBs
│   └── fixtures/                 # Small sample multisite SQL dumps for tests
├── var/
│   ├── logs/                     # Run logs (timestamped)
│   ├── reports/                  # Audit + migration + content-audit reports
│   └── state/                    # Resumability checkpoints (per batch)
├── composer.json
├── phpcs.xml
├── phpstan.neon
├── phpunit.xml
└── README.md
```

All three tools are **plain PHP CLI scripts using PDO directly** (no WordPress bootstrap).
This avoids double-bootstrapping two different `wp-config.php`s in one process and gives
full control over batching and transactions. All WordPress-specific knowledge (table
schemas, serialization formats, option keys) is encoded explicitly in the migrator
classes, with docblocks explaining the WP internals being replicated.

### 3.1 On reusing `~/sites/phpunit-testing`

That framework is described by you as still in development and not
updated in months, with a nontrivial setup (two PHPUnit versions,
specific folder conventions). To avoid entangling this project's
reliability with an unrelated WIP framework, **v1 will use a
self-contained, vanilla PHPUnit 11 setup** local to
`merge-multisite` (its own `composer.json`, `phpunit.xml`, `tests/`
tree) rather than depending on `phpunit-testing` directly. If, once
this project is underway, you'd like to try wiring it up to
`phpunit-testing`'s Unit/WP-Mock/Integration structure for consistency
across your projects, that can be a later, optional migration —
isolated so a `phpunit-testing` problem can't block this project's own
tests. Let me know if you'd rather I attempt the integration from day
one instead.

## 4. Configuration

### 4.1 `config.php` (gitignored; sample committed)
- Source: DB host/port/name/user/pass, **network table prefix is a
  required config value, not assumed** (e.g. your `wp3_`), plus each
  site's own sub-prefix is derived from it (`{prefix}{blog_id}_...`)
  per WordPress convention, but the base prefix itself is always read
  from config, never hardcoded as `wp_`.
- Source `wp-content/uploads` filesystem path.
- Destination: DB host/port/name/user/pass, table prefix, destination
  `wp-content/uploads` path.
- **`destination_url`** — the single canonical domain the merged site
  will live at (e.g. `https://glerner.com`). Every included subsite's
  own domain is rewritten to this in migrated content, options, and
  the redirect map (see §7.6). This is independent from whatever
  `siteurl`/`home` your destination WordPress install is actually
  configured with today (e.g. a `.lndo.site` Local URL) — that's a
  separate, standard WP concern handled by your normal
  Local/production workflow, not by this tool.
- `generate_redirect_files` (bool, default `true`) — set to `false`
  when migrating into a purely local/throwaway destination where
  redirects are meaningless; turn on for the real production-bound run.
- **`main_site`** (implemented, optional int blog_id) — the site whose
  variant wins whenever an element exists on several sites but only one
  can survive: term-name case ties (§6), duplicate
  `wp_template`/`wp_template_part` slugs like `header` (§9
  template-slug-collision check), and site-identity elements generally.
  Unset = no preference, ties fall back to lowest blog_id.
- Options: batch size, `--dry-run`, log level, term-merge case-rule
  (default: most-used-wins, overridable), post-type/post-status
  include/exclude lists (see §7.1 open item, now resolved with
  defaults + override).
- **`connection` endpoint drivers** (implemented): each side's `host`/
  `port` can be a fixed value or a driver spec — `'lando'` (reads the
  current mapped port out of `lando info`, survives Lando restarts
  that remap host ports) or `'local'` (Local by Flywheel `sites.json` /
  unix socket). Connection failures get a troubleshooting message, not
  a bare PDO fatal.
- **`suppressions`** (implemented): array of rules hiding audit
  findings — `['check' => '<name-or-prefix*>', ...context criteria]`.
  A suppressed-count line keeps it from being fully silent.
- **`media_search_paths`** (implemented): extra directories searched
  recursively (by filename, preferring path-suffix matches) for
  attachment files the checker finds missing; matches become a
  reviewable bash copy script (`var/reports/copy-missing-media-*.sh`)
  restoring them into the source uploads tree.

### 4.2 `sites.php` (your site selection)
- Explicit array of subsites, **one entry per site**, each with
  `blog_id`, `domain`/`path`, an `include` boolean, and **optional**
  override keys in the *same* entry (simplest structure, per your
  preference) — no separate arrays to keep in sync:
  ```php
  [
      'blog_id'      => 7,
      'domain'       => 'website-tech.glerner.com',
      'include'      => true,
      'category_name'=> 'Website Tech',   // optional, defaults to site title
      'category_slug'=> 'website-tech',   // optional, defaults to sanitized name
  ],
  [
      'blog_id' => 12,
      'domain'  => 'molten-salt-reactor.glerner.com',
      'include' => false,
  ],
  ```
- Defaults to `include = true` for every non-deleted site
  (`wp_blogs.deleted = 0` / `wp_site` + `wp_blogmeta`), so you only need
  to list exceptions.
- A helper command `php bin/migrate.php --list-sites` prints all
  non-deleted sites with their IDs/domains so you can copy-paste into
  this file.
- No "shared media library subsite" special-case in v1 — you don't have
  one, and it's not a common-enough WP multisite pattern to justify the
  complexity up front. Because every site's media already flows through
  the normal per-site migration + hash-based dedup (§7.2), a subsite
  that happens to hold only shared assets is handled correctly anyway
  (its files just dedup against every other site referencing them) —
  no special code path needed.

## 5. Global ID remapping strategy

A central `IdMap` component (backed by an in-memory array during a run,
persisted to `var/state/idmap-{run-id}.json` for resumability) tracks,
per entity type, `old_site_id + old_id -> new_id`:

- Users (network-wide table, so keyed by old `user_id` only — see §7.3)
- Terms (category/tag) — keyed by normalized name+taxonomy after merge
  resolution, not by old term_id, since terms merge across sites
- Posts (per source site + old post ID)
- Comments (per source site + old comment ID)
- Attachments (special case of posts, but also tracked by
  old-file-path → new-file-path for the filesystem move)
- Menus / nav menu items (per source site + old ID)

All migrators run in a fixed dependency order so that by the time e.g.
`PostMigrator` needs a term ID or author ID, it's already in the map:

1. Users
2. Terms (categories + tags), including cross-site merge resolution
3. Media files + attachment posts
4. Posts/pages/CPTs (+ postmeta, + `_thumbnail_id`/attachment
   references rewritten via IdMap)
5. Comments (+ commentmeta)
6. Menus & widgets
7. Generic options + plugin-specific data
8. URL rewriting + redirect map generation (needs final URLs from step 4)

Every rewrite of a serialized PHP value (postmeta, options, widget
settings often store serialized/JSON arrays containing old IDs or URLs)
goes through one shared, well-tested `SerializedDataRewriter` utility
that safely unserializes, walks, rewrites, and re-serializes — including
fixing the byte-length prefixes PHP's serialization format requires
(a common source of corruption in hand-rolled migration scripts).

## 6. Category/tag handling

- **New top-level category per included site**, named after the site's
  title (overridable per-site in `sites.php`, see §4.2), e.g.
  "Website Tech".
- Every migrated post keeps its original categories/tags **and**
  additionally gets the new "site" category assigned.
- Cross-site merging: categories/tags are matched **case-insensitively
  by trimmed name** (and taxonomy). Slugs are also normalized
  (case-insensitive) since WP slugs are typically already lowercase.
- When duplicates are found across sites with different casing (`php`
  vs `PHP`, `msr` vs `MSR`), canonical display name = **the variant
  used on the most posts** across all included sites. Ties are broken
  by **`main_site` when configured and the main site uses a variant**;
  otherwise first-encountered in site-ID order. This is computed in a
  pre-pass before any terms are written, so it's deterministic and
  reported in the audit output.
- The per-site term-candidate gather (term_id, name, taxonomy, count —
  currently written inline in `bin/site-audit.php` for the nav-menu
  merge labels and duplicated in `TermCaseCollisionCheck`) gets
  extracted into a shared `src/Migration/` component in Phase 3, since
  `TermMigrator` needs the identical query to drive the merge.
- Hierarchical categories (parent/child) are preserved per site; if two
  sites have a category with the same name but different parents, this
  is flagged in the report for manual review rather than silently
  guessed (ambiguous case).
- Output: a `term-merge-report.json`/`.md` listing every merge decision
  made, so you can review and override via `config/term-overrides.php`
  (`old label -> canonical label`) on a future run.

## 7. Content type specifics

### 7.1 Posts, pages, custom post types
- All post types migrated by default; `config.php` can exclude specific
  post types (e.g. `revision`, `nav_menu_item` handled separately,
  `customize_changeset`, other transient-like internal types) — a
  sensible default-exclude list ships out of the box.
- **Post status**, resolved per your confirmation: migrate everything
  except `auto-draft` and the `revision` post type; `trash`ed content is
  included by default but flagged distinctly in reports (config flags
  let you instead fully exclude trash, or exclude drafts/private too,
  per run).
- `postmeta` migrated key-for-key, with `SerializedDataRewriter` fixing
  any embedded IDs/URLs (e.g. `_thumbnail_id`, ACF field references,
  page builder data referencing other post IDs).
- Post `GUID` is regenerated for the destination (WP convention: GUID
  should reflect the site it lives on); a mapping of
  old permalink → new permalink feeds the redirect map (§7.6).
- Parent/child post relationships (`post_parent`, e.g. page hierarchies,
  attachment parents) rewritten via IdMap after all posts in a batch are
  inserted (two-pass: insert then fix `post_parent`, since WP doesn't
  guarantee parents are processed before children in ID order).
- **Plugin-owned custom post types need no adapter** — they migrate
  through the normal post pipeline as long as the type isn't in
  `excluded_post_types`. The only requirement is that the type is
  *registered* on the destination (install the plugin, or a small
  must-use stub registration) or the rows are invisible in wp-admin.
  Meta values containing other post IDs are remapped via IdMap like
  any other reference. Concretely confirmed: **Flamingo** — source has
  56 `flamingo_contact` posts (address-book entries, sites
  1/20/49/58/65) and zero `flamingo_inbound` submissions; keeping it =
  keep the post type included + Flamingo installed on destination.
- **Contact page normalization**: a configurable list of known contact
  page slugs/paths across sites (e.g. `/contact/`, `/contact-me/`,
  and any others discovered during audit) is treated as one logical
  "contact page" per merge. The migrator canonicalizes the **destination
  URL/slug to `/contact/`** and records every other variant in the
  redirect map so old links resolve correctly. It does **not** attempt
  to auto-convert other contact-form plugins' shortcodes/blocks into
  SureForms markup — rebuilding the actual form is a manual step (your
  `site-audit.php` tool, §8, will tell you exactly which pages use which
  form plugin so you know which ones need rebuilding in SureForms and
  which old plugins can then be deactivated).

### 7.2 Media / attachments
- Filesystem copy (per your choice), from each source site's
  `wp-content/uploads/sites/{blog_id}/YYYY/MM/*` into destination
  `wp-content/uploads/YYYY/MM/`. Your network started on WP 3.0/3.1 and
  has been kept current, so the modern `sites/{id}/` layout is expected
  for every site — but the migrator still **auto-detects** the legacy
  `wp-content/blogs.dir/{id}/files/...` layout (and files stored
  directly under `uploads/` with no site prefix, as the network's
  original main site sometimes has) as a defensive fallback, so the
  tool keeps working if an old, never-upgraded path is ever found.
- **De-duplication (same file, same name, across sites)**: files are
  compared by **size + SHA-256 hash** before copying. If an identical
  file already exists at the destination path, it's reused (the new
  attachment post points at the existing file) instead of copied again
  — this is what lets a shared logo across sites collapse to one
  physical file. Every generated thumbnail size referenced in
  `_wp_attachment_metadata` is deduped/copied the same way, with the
  metadata array rewritten to the new relative paths.
- **Name collisions with different content** (same filename, different
  bytes, from different sites) are renamed as:
  `{basename}_site{old_blog_id}.{ext}`, applied consistently across the
  original file *and all of its generated thumbnail-size variants* so
  WordPress's own size-suffix pattern (`-150x150`, `-1024x768`, etc.)
  still lines up with the renamed base, e.g. `logo_site7.png`,
  `logo_site7-150x150.png`. This keeps every size variant traceable to
  its source site rather than an arbitrary `-2`/`-3` counter.
- **Duplicate content, different filenames** (e.g. the same photo
  uploaded to the Media Library twice under different names — a common
  WP occurrence): **not** auto-merged during migration in v1, since two
  attachment posts with the same bytes may legitimately have different
  alt text/captions/usage, and auto-merging risks silently repointing
  something incorrectly. Instead this is a dedicated, clearly-separated
  section of the integrity-checker report (§8) — informational, so you
  can manually clean these up in the source (or destination) if you
  want to reclaim space; each entry also notes the impact is minimal
  unless the file is large.
- Implemented as PHP (not a separate bash script, per your preference —
  Bash becomes hard to maintain and test past ~20 lines) so it can
  participate in the same dry-run/reporting/resumability infrastructure,
  and so dedup-by-hash logic is unit-testable. `MediaMigrator` is a
  standalone class/phase, invokable on its own via:
  `php bin/migrate.php --move-media-only [--dry-run]` — moves/dedupes
  files and creates/updates attachment posts without touching
  posts/terms/comments/etc., for when you just want to get media in
  place first (composable with `--dry-run`, as you requested).

### 7.3 Users
- Registered users are **preserved individually** (not consolidated).
  Destination is created with an initial admin user (you provide its
  user ID/login in config so the migrator never touches or duplicates
  it).
- In a genuine WordPress multisite, users are already global/shared
  (one `wp_users` table for the whole network, `user_login` and
  `user_email` both unique across it), so no cross-site merging is
  normally needed — the migrator simply copies each user once
  (`user_login`, `user_pass` hash as-is, `user_email`, `display_name`,
  registration date) and, per user, resolves the destination role to
  the **highest role held across all included sites** (logged in the
  report).
- Because this tool is meant to be reusable beyond a true single
  network (e.g. a future run against sites that weren't always one
  network, or data cleaned up outside WP's own constraints), the
  **integrity checker still defensively checks** for:
  - Same `user_login`, same `user_email` → not a conflict, same person,
    migrated once.
  - Same `user_email`, different `user_login` or `display_name` →
    flagged for your manual review (likely the same person under
    different accounts).
  - Same `user_login`, different `user_email` → flagged as a hard
    conflict for manual resolution (WordPress itself would never allow
    this in one network, so seeing it indicates the source data needs
    attention before migrating).

### 7.4 Comments
- Migrated with `comment_post_ID` and `user_id` rewritten via IdMap;
  threaded replies (`comment_parent`) fixed in a second pass like
  post_parent.
- `commentmeta` migrated with the same `SerializedDataRewriter`.

### 7.5 Menus, widgets, and plugin settings/options

**Menus.** WordPress core has no built-in way for one nav menu to
include/nest another menu; that requires a plugin (e.g. *Menu In Menu*
or *Navception*, both do essentially the same thing: embed one saved
menu as a submenu item inside another). *Ollie Menu Designer*, which
you mentioned, is actually a block-based **menu design/styling** tool
(mega-menu layouts, images/buttons inside dropdowns) — not a
cross-site menu-merging tool, so it doesn't help combine your
subsites' menus, though it (or a similar plugin) could be useful later
for styling the final destination menu.

Given that, rather than guessing at a merged menu structure, v1:
- Migrates each source site's menu(s) as **data** (as their own
  `nav_menu` terms + `nav_menu_item` posts, not assigned to any theme
  location — inactive/orphaned, per your confirmation), with item
  targets (`_menu_item_object_id`) rewritten via IdMap so they still
  point at the correct (possibly-recategorized) posts/pages.
- **Also generates a plain-text/Markdown tree view** of every included
  site's menu structure (labels, URLs, nesting) as a
  `menus-overview.md`/`.json` report — a fast reference for you to
  manually rebuild one clean destination menu by hand (or via a plugin
  like *Menu In Menu* if you want to keep some submenus reusable across
  multiple menu locations), rather than trying to algorithmically merge
  N independent menus (e.g. your Categories + Contact + light/dark-mode
  toggle link pattern) into one coherent nav — that's a design decision
  best made by you, not guessed by a script.

**Widgets.** `sidebars_widgets` and each widget's `widget_{type}` option
migrated per site, namespaced (e.g. prefixed sidebar name) as
**inactive/orphaned data** (per your confirmation) since widget areas
aren't naturally site-scoped once merged — flagged in report for manual
placement review, since blindly merging widget areas from N sites into
one theme's sidebars is not safe to automate.

**Generic options.** A configurable allowlist (`config/option-keys.php`)
lists which `wp_options` keys to bring over per plugin. Seeded from the
plugin slugs you listed across your sites/clients (see below); easily
extended for anything not covered.

**Plugin inclusion rule** (revised per your feedback): the migrator
considers a plugin's data eligible for migration if the plugin is
**installed on the source site, whether currently active or not** (you
pointed out things like `query-monitor`, or a migration tool you only
activate occasionally, are legitimately inactive most of the time but
still "belong" to the site). The integrity checker additionally flags:
  - Plugins with data **in the database** but **not currently
    installed** at all (leftover cruft from a removed plugin) — for you
    to decide whether to migrate or discard.
  - Any active/installed plugin with **no configured handling** in
    `option-keys.php` and no adapter in `src/Plugins/` — so nothing is
    silently dropped.

**Default `option-keys.php` seeding**, from the plugin lists you
provided across your sites/clients:
  - **Generally include** (site-identity/config, safe & useful):
    `wordpress-seo` / `autodescription` (SEO Framework) /
    `wordpress-seo-premium`, `redirection`, `wpforms-lite`, `ws-form`,
    `ninja-forms`, `contact-form-7`, `formidable`, `sureforms`,
    `woocommerce` core settings, `elementor`/`elementor-pro` global
    settings, `astra-addon`/theme-builder settings,
    `ultimate-addons-for-gutenberg`, `accessibility-checker`,
    `relevanssi`, `two-factor` (user meta only, not secrets — see
    below), `simple-cloudflare-turnstile`.
  - **Include settings but explicitly exclude security-sensitive
    sub-data**: `wordfence` (keep general firewall/scan configuration;
    **exclude** blocked-IP lists, live-traffic/login-attempt logs —
    transient security data, not settings) — matches your note that
    it's fine either way but you don't need the block lists.
  - **Exclude by default (security-sensitive, credentials-bearing)**:
    backup/migration tools that store remote-storage or DB-transfer
    credentials — `updraftplus` (its S3/remote-storage credentials are
    not something a migration script should silently copy),
    `wp-migrate-db`, `prime-mover`, `duplicator-pro`, and similar. These
    are flagged in the audit report as "detected, intentionally
    excluded — reconfigure manually on destination" rather than
    migrated. Any older/replaced backup plugin's data (you mentioned an
    older backup plugin superseded by UpdraftPlus) is excluded outright
    as obsolete.
  - **Exclude as dev-only / no meaningful DB footprint**:
    `query-monitor` (confirmed by you as unlikely to have DB data worth
    migrating).
  - **Pods — decided: detector only, no adapter.** You installed the
    plugin but never built Pod content types, so there's no custom
    table data to migrate. The integrity checker's `pods-detection`
    section stays (it confirms that at a glance and catches any Pods
    tables on a future reuse of this tool), but no `PodsAdapter` gets
    written.
  - Everything else you listed (`ai-engine`, `akismet`,
    `advanced-custom-fields`, `google-site-kit`, `instant-images`,
    `litespeed-cache`/`object-cache.php` (server/cache config, not
    portable — excluded), `media-library-assistant`, `media-sync`,
    `mailin`, `mp3-music-player-by-sonaar`, `quiz-master-next`,
    `learndash-*`, `woocommerce-*` add-ons, etc.) will get a best-effort
    generic-options entry where it's a simple settings array, and be
    listed as "no adapter yet" in the report otherwise — nothing
    migrates silently without appearing in a report first.
  - **Decided so far against the real DB**: `akismet` → include
    (`akismet_*`). `flamingo` → keep its `flamingo_contact` CPT (§7.1);
    no `flamingo_inbound` submissions exist. `ai-engine`, `gl-reinvent`,
    `greenshift-*`, `instant-images`, `mailin`, `media-library-assistant`,
    `media-sync`, `optimization-detective`, `phoenix-media-rename`,
    `regenerate-thumbnails`, `safe-svg`, `wp-graphql`, `wpwm-cfce-plugin`,
    `wpwm-theme-variation-display` → verified **no data anywhere**
    (options/postmeta/posts/tables all empty); suppressed in config
    rather than given exclude rules since there is simply nothing to
    migrate. `000-prime-mover-constants` → exclude.
  - Your two custom plugins (`gl-block-bad-logins`,
    `gl-debug-mode-only-you.php`) are **ignored completely**, per your
    instruction — not even reported.

### 7.6 URL rewriting & redirects

This is the most consequential correctness area, so to be precise about
what changes:

- **Every included subsite's own domain is rewritten to
  `destination_url`.** This is not limited to cross-links between
  subsites — the vast majority of internal links/image references on a
  WordPress site point at *its own* domain (e.g. a post on
  `website-tech.glerner.com` linking to
  `https://website-tech.glerner.com/some-other-post/`). All such
  self-referential absolute URLs, across post content, postmeta,
  widget/menu data, and migrated options, are rewritten to
  `https://glerner.com/...` (using your configured `destination_url`)
  with the correct new path (through the category/slug the post now
  lives at).
- Links to **excluded** sites (e.g.
  `molten-salt-reactor.glerner.com`) or to genuinely external domains
  are left untouched.
- You explicitly do **not** want source hostnames swapped for a
  *local* staging hostname (e.g. `*.lndo.site`) — that's correctly out
  of scope per §2; `destination_url` should be set to the real eventual
  production domain (`glerner.com`) even while testing locally, and
  your normal Local↔production URL-swap workflow handles the
  local-environment part separately, as it always would for any WP
  site move.
- Contact-page variants (`/contact-me/`, etc.) are canonicalized to
  `/contact/` as part of this same rewriting pass (§7.1).
- **Redirect map output, multiple formats** (generated together every
  run where `generate_redirect_files` is true):
  1. `redirects.json` — full structured data (old URL, new URL, source
     site, post ID, status code).
  2. `redirects.md` — human-readable Markdown table.
  3. `redirects.csv` — generic 2-column `source,target` CSV, compatible
     with the **Redirection** plugin's CSV importer (verified: accepts
     `source URL,target URL[,regex,http code,type]`).
  4. `redirects-yoast.csv` — Yoast SEO Premium's required 4-column
     format (`"Origin","Target","Type","Format"` header, `301`/`plain`
     rows) — verified this is Yoast's documented import format, distinct
     enough from Redirection's that both are worth generating.
  5. `redirects.htaccess` — Apache `RedirectMatch`/`Redirect 301` block.
  6. `redirects-nginx.conf` — Nginx `rewrite ... permanent;` block (for
     Nginx website hosting).
  All six are cheap to generate together from the same underlying map,
  so there's no need to pick just one; you choose whichever your final
  host/plugin needs at cutover time.

## 8. Content/plugin-usage audit tool (`bin/site-audit.php`)

A new, separate, **read-only** tool answering: *"across this site (or
subsite, or whole multisite), what non-core blocks and which
content-affecting plugins are actually in use, and on which
posts/pages?"* — so you can decide which duplicate plugin to standardize
on (e.g. pick one contact-form plugin) and know exactly which pages need
edits, both before and after migration.

- **Scope flag**: `--site=<blog_id>` (one subsite), `--all-sites` (every
  non-deleted site in a multisite), or run against a single-site
  install's DB directly — same underlying scanner, just a different
  site-selection layer, reusing `SiteSelector`/config from the other
  tools.
- For every post/page (and CPTs, optionally filtered by post type), the
  scanner:
  - Parses Gutenberg block comments (`<!-- wp:namespace/block-name -->`)
    out of `post_content` and records every **non-core** block
    namespace/type encountered (core blocks like `core/paragraph` are
    ignored by default, configurable) — this surfaces Elementor
    (`post_content` markers / `_elementor_data` postmeta), Divi
    (shortcode-based `[et_pb_section]` markers / `_et_pb_use_builder`
    postmeta), and native block-plugin usage alike, using
    per-page-builder detector rules (not just raw block parsing) since
    Elementor/Divi/WooCommerce/SureCart don't all use plain Gutenberg
    block comments.
  - Detects known **contact-form** plugin signatures per page: Contact
    Form 7 (`[contact-form-7 ...]`), WPForms/WPForms Lite
    (`[wpforms ...]` / block), Ninja Forms, Gravity Forms, Formidable,
    WS Form, SureForms — reporting which plugin+form ID appears on which
    URL/slug.
  - Detects **photo gallery** blocks/shortcodes (core gallery, Envira,
    NextGEN, etc.), **video embeds** (YouTube/Vimeo block or oEmbed,
    self-hosted `<video>`, specific plugin embeds), **social-icon**
    blocks/widgets, **SEO** plugin in use per page (Yoast vs SEO
    Framework vs others, detected via their respective postmeta keys),
    and **ecommerce** usage (WooCommerce vs SureCart product
    blocks/shortcodes).
  - Extensible detector registry (`src/ContentAudit/Detectors/`) — each
    detector is a small class matching content patterns/postmeta keys
    for one plugin/feature, so adding a new detector for a plugin I
    haven't seen yet is a short, testable addition.
- **Output**: CSV (one row per post/page, columns for
  site/URL/post-type/status/each detected category, e.g. `blocks_found`,
  `form_plugin`, `page_builder`, `gallery_plugin`, `video_type`,
  `seo_plugin`, `ecommerce_plugin`), designed to open cleanly in Excel
  or be pasted straight into Google Sheets; a JSON version too for any
  future tooling. A summary sheet/section tallies "N pages use Contact
  Form 7, M pages use WPForms" etc. to make the "which plugin do I
  standardize on, and which pages need editing" decision fast.
- Independent of the migration pipeline — usable standalone, before you
  even decide on this whole merge project, or afterward on the finished
  single site to verify cleanup.

## 9. Pre-flight integrity checker (`bin/multisite-integrity-checker.php`)

Read-only, connects only to the source DB (+ filesystem for media
checks). Checks include:

- Every non-deleted site is reachable/has a valid table prefix
  (configured base prefix, not assumed `wp_`).
- Every post's `post_author` refers to an existing user.
- Every post's `post_parent` refers to an existing post (or 0).
- Every attachment's file exists on disk at the expected path
  (auto-detecting modern vs. legacy upload layout); flags missing
  files.
- **Media file report, clearly split into separate, sorted sections**
  (a file can legitimately appear in more than one section, e.g. it's
  identical to one same-named file but conflicts with another):
  1. **Errors — same filename, different content** (will require the
     `_site{blog_id}` rename during migration): listed first/most
     prominently, sorted by filename.
  2. **Informational — same filename, identical content** (will safely
     dedup to one physical file): separate list, for debugging/
     verification, not mixed in with the errors above.
  3. **Informational — identical content, different filenames**
     (duplicate Media Library uploads): separate list, not auto-merged
     (§7.2), noted as low-impact unless the file is large.
- Duplicate `user_login`/`user_email` conflict detection per the rules
  in §7.3.
- Orphaned postmeta/commentmeta (rows referencing a deleted post).
- Category/tag case-collision report (preview of what term-merge will
  do, before you commit to running the real migration).
- Plugin data vs. `option-keys.php` allowlist cross-check (installed
  vs. active, per §7.5), plus "data in DB but plugin not installed"
  detection.
- Pods custom-table/relationship detection (§7.5).
- Menu items / widgets referencing missing objects.
- **Duplicate `wp_template`/`wp_template_part` slugs across sites**
  (implemented as `template-slug-collision`): block-theme templates —
  headers and footers live in `wp_template_part` — are keyed by
  `post_name`, so N sites each having a `header` template part collide
  on merge. The check lists every slug on >1 site with each site's
  title/status, and names the `main_site` winner when configured.
- Contact-page slug variants discovered (feeds the `/contact/`
  canonicalization list in §7.1, so you can confirm the list before
  migrating).
- Divergent site-wide options (same option name, different values across
  subsites) — the merged site keeps one value per option (unknown which
  one will be kept).

**Report conventions** (implemented, after an audit-noise reduction pass):

- Every `### <check>` section opens with a one-line `description()`
  from the check class, so individual finding lines stay terse —
  `Site N, Post X "title" (post_type) field=value -- assessment` for
  post findings, `"option": "v1", "v2"` or site-grouped value lines for
  divergent options, `Site N, Option "x" ...` for option findings.
- `suppressions` in `config.php` filters findings centrally in
  `AuditRunner` by check name (prefix `*` allowed) + context match;
  one `audit.suppressed` line reports the hidden count.
- `excluded_post_types` is respected by the post checks, not just the
  migrator.
- Plugin section output: per-plugin `no-rule` warnings stay one line
  each; a `plugin-data.undecided` finding lists paste-ready rules
  grouped by intent — `include` lines only where option rows were
  actually detected (prefix pre-filled from the slug's common
  spellings, with a `// found:` comment), a "data lives elsewhere"
  block probing postmeta keys / post types / custom tables, then
  `exclude` lines and `suppressions` lines. `mode => 'exclude'` in
  option-keys.php means "decided: never migrate, don't report."
- Orphaned plugin data is one Markdown table (plugin rule(s) |
  pattern | site | rows | unique names); rules sharing an option
  pattern merge into one row.
- Missing attachment files are searched for under
  `media_search_paths`; results become `copy-missing-media-*.sh`
  (`install -D` commands into the source tree, `# NOT FOUND:` comments
  for the rest — review before running).

Output: a Markdown report (human-readable, safe to read top-to-bottom)
plus a JSON version (for scripting/diffing between runs) in
`var/reports/integrity-{timestamp}.{md,json}`. Non-zero exit code if
blocking issues are found; `--strict` flag to also fail on warnings.

## 10. Migration execution model

- `php bin/migrate.php --dry-run` — runs the full pipeline, logs every
  planned write, writes the same reports as a real run, but issues no
  writes to the destination DB and copies no files. Strongly
  recommended before every real run.
- `php bin/migrate.php --move-media-only [--dry-run]` — media-only
  phase, standalone (§7.2).
- `php bin/migrate.php` — real run, wrapped in destination-DB
  transactions **per batch** (not one giant transaction, for memory/lock
  reasons), with a checkpoint file after each successfully committed
  batch so an interrupted run can resume with `--resume` instead of
  starting over or double-inserting.
- **DDL stays outside transactions.** MySQL implicitly commits before
  and after any DDL statement (`CREATE TABLE`, `ALTER TABLE`), so a
  DDL inside a batch transaction would silently commit the pending
  writes and make `rollBack()` a no-op. All schema setup —
  `{prefix}_merge_migration_map`, any helper tables — runs up front,
  before the first batch transaction opens.
  (Data Definition Language is a subset of SQL commands used to define, modify, and delete database schema objects such as tables, indexes, views, and stored procedures.)
- Batching: configurable batch size (default e.g. 200 posts/comments per
  batch) — matters for reusability on larger multisites even though
  yours is small.
- Idempotency safeguard: every inserted destination row's origin
  (`old_site_id`, `old_table`, `old_id`) is recorded in a small
  migration-tracking table (`{prefix}_merge_migration_map`) created on
  the destination DB, so re-running detects "already migrated" rows and
  skips/updates rather than duplicating — this is also how `--resume`
  and future incremental re-runs work.
- Structured logging (`var/logs/migrate-{timestamp}.log`) at
  info/warning/error levels; a final summary (counts per entity type,
  per site, elapsed time, warnings) printed to console and saved.
- **Recommended workflow** (matches how you said you'll actually use
  this): run the migration into a **local** destination WordPress install
  first, verify the result thoroughly in the browser/admin, and only then
  promote that verified database + uploads to production using your normal
  tooling (a `.sql` export, or UpdraftPlus with its S3 connection) — this
  tool intentionally stops at "correct local destination DB + files", not
  production deployment.

## 11. Testing & code quality

- `composer.json` targeting PHP 8.2+ (you're on 8.4 locally), its own
  self-contained PHPUnit 11 setup (see §3.1 re: `phpunit-testing`).
- **PHPUnit** unit tests for all pure logic: `IdMap`, term-merge
  case/most-used resolution, `SerializedDataRewriter`, media dedup/hash
  comparison, media rename scheme, redirect map building (all 6
  formats), URL-rewriting rules, site-selection filtering, and every
  `site-audit.php` detector.
- **Integration tests** against small fixture MySQL/SQLite databases
  (a miniature 2-site multisite fixture with a handful of posts, terms,
  users, comments, and media) exercising each Migrator end-to-end.
- **PHPCS** with WordPress Coding Standards (`wpcs`), **PHPStan** for
  static analysis.
- Full docblocks (`@param`, `@return`, `@throws`) on every public
  method, and inline comments explaining any non-obvious WordPress
  internals being replicated (e.g. serialization quirks, GUID rules).
- `README.md` with setup, config, and full usage instructions for all
  three tools, plus a "how to add a plugin adapter" / "how to add a
  content-audit detector" guide for reuse on other multisites.

## 12. Phased delivery

1. **Phase 0** — scaffolding: composer.json, phpcs/phpstan config,
   directory layout, config loading, DB connection wrappers, logging,
   CLI arg parsing. ✅ Done — incl. dynamic endpoint drivers
   (static/lando/local) so Lando port remaps stop breaking runs.
2. **Phase 1** — `multisite-integrity-checker.php` fully working
   end-to-end against your real source DB (read-only, safe to run
   immediately, gives us real data to validate every later assumption
   against). ✅ Done and in active use — all 13 checks plus the
   report-noise machinery (§9 conventions); 15 errors / ~112 warnings
   on the current real data, all categorized and actionable.
3. **Phase 2** — `site-audit.php` (independent of the migration
   pipeline; also safe to run immediately, and useful on its own for
   your plugin-consolidation decisions). Built; hardening/report polish
   is the natural next candidate before Phase 3+.
4. **Phase 3** — Users + Terms migrators (+ term-merge report).
5. **Phase 4** — Media migrator (filesystem copy/dedup/rename,
   `--move-media-only`).
6. **Phase 5** — Posts/pages/CPTs + postmeta + parent-fixups + contact
   page canonicalization.
7. **Phase 6** — Comments.
8. **Phase 7** — Menus/widgets (as orphaned data + tree-view report) +
   generic options + plugin adapters.
9. **Phase 8** — URL rewriting (domain collapsing) + redirect map (all
   6 formats).
10. **Phase 9** — Full dry-run against your real data, review reports
    together, then a real run against your local destination site, your
    verification, then your own production promotion.

Each phase ships with its own tests and can be run/reviewed
independently rather than as one big-bang script.

## 12.5 Future enhancements (design notes, not committed work)

Long-term product ideas recorded so they stay on the record; none of
these are scheduled work:

- **Divi→Block Editor converter (long-term product idea).** Go through
  everything on a page that is from Divi (and, by extension, other page
  builders), convert everything that *can* be converted to native
  blocks automatically, and emit all the details needed to convert the
  remainder by hand. This project (site-audit's detector framework +
  raw-data extraction) is explicitly doing the preliminary detection/
  inventory work for it.
- **Per-builder "content inventory" exporter.** Extend `site-audit.php`
  (currently the `needs-review` CSV with raw dumps) into a structured,
  tree-view-like inventory of every element on a page per page builder
  (Elementor's `_elementor_data` JSON tree; Divi's shortcode attribute
  pairs; block JSON attributes), exportable to CSV/Google Sheets/MySQL.
  Goal: enough detail (e.g. "4th slide's filename, padding, background
  color") to rebuild a page from the original site without opening the
  original's editor. Whether this is genuinely useful is unproven --
  the raw dumps in the current needs-review CSV are the cheap
  experiment to find out.
- **Keep the source multisite unmodified.** A corollary of the above:
  the original multisite should be kept untouched (e.g. archived as-is)
  so each page can always be re-inspected in its original
  editor/theme/plugins while rebuilding pages on the destination. The
  tools never modify the source, but the *process* should treat the
  source as a reference archive until the rebuilt destination is
  verified.
- **"What needs rebuilding" workflow.** The needs-review report is the
  source of truth for which pages/widgets/blocks need manual attention;
  a future enhancement is a per-page review checklist (original site +
  page, destination page, plugins most likely needing checking on that
  page, and the raw data we have about each).

## 13. Remaining open items

Small items I don't yet have a definitive answer for; I've noted the
default I'll build if you don't weigh in further:

1. `phpunit-testing` integration: build this project's own standalone
   PHPUnit setup first (confirmed); once merge-multisite has real tests
   in place, it can double as a test case for exercising/hardening
   `phpunit-testing` itself later.
2. Exact destination `destination_url` value and destination admin
   user ID/login (needed once we actually configure `config.php` — not
   needed to start Phase 0/1/2).
3. ~~Whether Pods is actually in use~~ — resolved: the plugin was
   installed but no Pod content types were ever built; `pods-detection`
   stays as a confirming check, no `PodsAdapter` will be written.
4. Any additional contact-form-page slugs beyond `/contact/` and
   `/contact-me/` you already know about, so the canonicalization list
   is complete before Phase 5 — otherwise `site-audit.php` (Phase 2)
   will surface all of them from real data anyway.

## 14. What I will NOT do

- Won't touch anything outside `~/sites/merge-multisite`.
- Won't install/activate plugins or modify the destination site's
  configuration beyond what the migration DB writes strictly require.
- Won't guess at ambiguous merges (category parent conflicts, widget
  placement, menu merging, plugins without adapters) — these are always
  surfaced in reports for your decision rather than silently resolved.
- Won't migrate credentials-bearing plugin data (backup/migration tool
  remote-storage or DB credentials) — always excluded and flagged for
  manual reconfiguration instead.

---

**Next step:** Phase 3 (Users + Terms migrators) — the read-only audit
tooling (Phases 0–2) is built and verified against the real source DB.
