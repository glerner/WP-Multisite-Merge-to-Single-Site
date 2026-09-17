# Code review — 3ce6bb1 (2026-09-15)

"Add plugin-usage rollup and XLSX output to site-audit; cut report noise across detectors"

## Summary

- Scope: commit 3ce6bb1 (working tree clean, so last commit reviewed)
- Date: 2026-09-15
- Status: 2 Pending (CR-001/CR-003 now Done), rest N/A

## Decisions / Constraints

- Findings must stay decision-oriented; suppression is configurable, never silent.
- `ScannedPost::$meta` is `meta_key => string[]`; test fixtures mirror this.
- The rollup is advisory — installed-but-undetected plugins are review candidates,
  not auto-removals (spam/security/performance plugins legitimately leave no markup).

## Unified Action Checklist

- CR-001 — Status: Done · Priority: MEDIUM
  - Finding: `PluginUsageRollup::resolveEntity()` checks `NOT_A_PLUGIN` by prefix before
    consulting installed slugs. A condensed label like `gallerypro` or `youtubeembedplus`
    starts with the `gallery`/`youtube` tokens and is silently dropped — so a genuinely
    installed plugin whose label begins with a core token lands in `not_detected` ("no
    content footprint") even though its output was detected.
  - Impact: False "safest removal candidate" for a plugin actually in use — exactly the
    wrong direction for this report's purpose.
  - Source refs: src/ContentAudit/PluginUsageRollup.php `resolveEntity()`
    (`str_starts_with($condensed, $notPlugin)` runs before the installed-slug loop).
  - What remains: Either exact-match `NOT_A_PLUGIN`, or check the installed-slug prefix
    match first and only fall through to `NOT_A_PLUGIN` when no installed slug matched.

- CR-003 — Status: Done · Priority: LOW
  - Finding: In bin/site-audit.php, a network-active plugin that also appears in a
    site's own `active_plugins` option gets that blog_id appended twice to
    `$activeBySlug`. Consumers (`roleConflicts`, `not_detected`) all `array_unique`,
    so output is correct today — the map itself is just built dirty.
  - Impact: Latent surprise for any future consumer that counts instead of dedupes.
  - Source refs: bin/site-audit.php, the two loops filling `$activeBySlug`.
  - What remains: Dedupe at build time (`$activeBySlug[$slug][$site->blogId] = true`
    keyed set, or `array_unique` before passing) or document that callers must dedupe.

## Positive Observations (N/A)

- `PluginFootprintDetector` extraction is clean — `PluginDataCheck` lost ~200 lines of
  probing code and both callers share `suggestedOptionKeys()`.
- `postTypeClause()` correctly makes an explicit `--post-types` list win over
  exclusions — you can still audit `revision` on purpose (PostScanner.php).
- The `?->` before `??` removals in `Site.php` are correct, not just lint-appeasing:
  `??` evaluates under `isset()` semantics, so `null->prop ?? default` was always safe.
- `NOT_A_PLUGIN` handling of the new `'HTML form (action: ...)'` label works: it
  condenses to `htmlformaction...` and is correctly filtered.
- `roleConflicts` distinguishes same-site co-activation (real conflict) from
  different-sites (consolidation) — the right semantic for the SMTP/wp_mail() case.
- Frozen `C2` pane puts `blog_id` + `original_url` in the locked columns — identity
  survives horizontal scroll, matching the docblock.
- Meta fixtures in the new tests use `key => array(value)` — the production shape.


# Full-program review — second pass (2026-09-15)

Scope: entire codebase (src/, bin/, config/), not just the commit. 8 medium,
15 low findings. The 'e.g.' dead entry from CR-002 was fixed during this pass.

## Decisions / Constraints

- Audit is read-only; findings must stay decision-oriented (grouped, actionable).
- ScannedPost meta is meta_key => string[]; serialized options must be unserialized.
- Reports suppress noise via config, never silently.

## Unified Action Checklist — MEDIUM

- CR-101 — Done · MEDIUM
  - Finding: MenuWidgetIntegrityCheck joins _menu_item_object_id against the posts
    table only. Taxonomy menu items store a term_id there (wp_terms), so every
    "Category X" menu link is reported "links to missing object ID" -- a false
    positive for the check's most common real-world case. A term_id colliding
    with a real post ID silently passes (false negative).
  - Source: src/Audit/Checks/MenuWidgetIntegrityCheck.php:48-53
  - Fix: read _menu_item_type first; 'taxonomy' -> check wp_terms.term_id,
    'post_type' -> posts join, skip 'custom'/'post_type_archive'.

- CR-102 — Done · MEDIUM
  - Finding: orphanedDataFinding() suggests pasting
    ['check' => 'plugin-data.orphaned-data', 'plugin' => '<slug>'] into
    suppressions, but the finding's context has no 'plugin' key (it carries
    'rows' => list). SuppressionFilter compares top-level context keys, so the
    suggested rule can never match -- the user pastes it and nothing is hidden.
  - Source: src/Audit/Checks/PluginDataCheck.php:340-349, src/Audit/SuppressionFilter.php:97-112
  - Fix: emit one finding per pattern with 'plugin'/'pattern' context keys, or
    correct the hint.

- CR-103 — Done · MEDIUM
  - Finding: MediaFileCheck fingerprints every found file twice --
    checkFilenameCollisions() and checkDuplicateContentDifferentNames() each
    hash the full contents of every attachment. The dominant cost of the check
    on a real media library.
  - Source: src/Audit/Checks/MediaFileCheck.php:184-187,240-242
  - Fix: fingerprint once in run(), share the map.

- CR-104 — Done · MEDIUM
  - Finding: fetchMetaForPosts() builds one IN(...) with a placeholder per post;
    MySQL caps prepared statements at 65535 placeholders, so a site with >65k
    posts fails outright.
  - Source: src/ContentAudit/PostScanner.php:260-276
  - Fix: chunk post IDs (~5000 per query).

- CR-105 — Done · MEDIUM
  - Finding: needs-review CSV's post_title column is always empty: toCsv()
    reads $row->categoryFindings['post_title'], but post_title is a
    ContentAuditRow property, not a detector category -- the lookup always
    misses. Should be $row->postTitle.
  - Source: src/Report/NeedsReviewReportWriter.php:105

- CR-106 — Done · MEDIUM
  - Finding: PluginUsageRollup::resolveEntity() prefix-matches NOT_A_PLUGIN
    before consulting installed slugs. A label condensing to e.g. "gallerypro"
    or "youtubeembedplus" is dropped as "core output", so a genuinely installed
    plugin starting with a core token lands in not_detected as "no footprint" --
    a false safest-removal-candidate. (Duplicate of CR-001 above; fixed together.)
  - Source: src/ContentAudit/PluginUsageRollup.php resolveEntity()
  - Fix: try installed-slug match first; NOT_A_PLUGIN only on no match.

- CR-107 — Done · MEDIUM
  - Finding: Beaver Builder gallery signature can't fire: META_SIGNATURES
    searches _fl_builder_data for '"type":"gallery"', but that meta is
    PHP-serialized (s:4:"type";s:7:"gallery"), so the needle never matches.
  - Source: src/ContentAudit/Detectors/GalleryDetector.php:42-46
  - Fix: unserialize and inspect, or match s:7:"gallery"-style needles.

- CR-108 — Done · MEDIUM
  - Finding: excluded_post_statuses is a dead config key for scans: loaded and
    documented, but PostScanner hardcodes post_status NOT IN
    ('trash','auto-draft'). Users adding e.g. 'draft'/'inherit' expect effect.
  - Source: src/Config/ConfigLoader.php:68, src/ContentAudit/PostScanner.php:108,173
  - Fix: wire into PostScanner alongside postTypeClause(), or document as
    migration-only.

## Unified Action Checklist — LOW

- CR-109 — Pending · LOW
  - Finding: harden-admin-id.php wraps statements in a transaction, but the
    final ALTER TABLE (DDL) implicitly commits in MySQL -- the UPDATEs are
    already durable if the ALTER fails; rollBack() can't undo them.
  - Source: bin/harden-admin-id.php:105-117, src/Destination/AdminIdRenumberer.php:62-73

- CR-110 — Pending · LOW
  - Finding: findOrphanedPluginData breaks after the first matching pattern per
    rule, so a rule with several patterns only reports the first with rows.
  - Source: src/Audit/Checks/PluginDataCheck.php:296

- CR-111 — Pending · LOW
  - Finding: NeedsReviewDetector copy-pastes PageBuilderDetector's
    Elementor/Divi/Beaver/10Web signature checks; the two can silently diverge.
  - Source: src/ContentAudit/Detectors/NeedsReviewDetector.php:32-43

- CR-112 — Pending · LOW
  - Finding: VideoEmbedDetector misses the modern <!-- wp:embed --> block and
    youtube.com/embed|vimeo.com/video URL forms.
  - Source: src/ContentAudit/Detectors/VideoEmbedDetector.php:25-31

- CR-113 — Pending · LOW
  - Finding: DivergentSiteOptionCheck only scans autoload='yes' options --
    non-autoloaded settings can't diverge in the report; blind spot is
    undocumented in the docblock.
  - Source: src/Audit/Checks/DivergentSiteOptionCheck.php:129

- CR-114 — Pending · LOW
  - Finding: DivergentSiteOptionCheck's ".truncated" message implodes ALL
    remaining option names unbounded when >50 diverge.
  - Source: src/Audit/Checks/DivergentSiteOptionCheck.php:213-218

- CR-115 — Pending · LOW
  - Finding: Widget check does one option lookup per widget instance (N+1);
    could be a single LIKE 'widget_%' fetch.
  - Source: src/Audit/Checks/MenuWidgetIntegrityCheck.php:102-112

- CR-116 — Pending · LOW
  - Finding: MalwareIndicatorCheck loads every published post's full
    post_content per site; large sites = large fetchAll. Chunk or push LIKE
    to SQL.
  - Source: src/Audit/Checks/MalwareIndicatorCheck.php:250-253

- CR-117 — Pending · LOW
  - Finding: searchSite reports only the first matching needle per post --
    ambiguous whether one-row-per-post is intended.
  - Source: src/ContentAudit/PostScanner.php:119-140

- CR-118 — Pending · LOW
  - Finding: PluginDataCheck calls installedSlugs()/mustUseSlugs() inside the
    per-site loop, rescanning the plugins dir each iteration.
  - Source: src/Audit/Checks/PluginDataCheck.php:74-76

- CR-119 — Pending · LOW
  - Finding: DatabaseConfig requires 'host' even for socket-only configs.
  - Source: src/Config/DatabaseConfig.php:61

- CR-120 — Pending · LOW
  - Finding: expandHome() applied only to media_search_paths; uploads_path etc.
    get no ~/ expansion -- inconsistent.
  - Source: src/Config/ConfigLoader.php:84-87,134-140

- CR-121 — Pending · LOW
  - Finding: original_url is approximate: built from post_name alone, so
    hierarchical child pages lose their parent path.
  - Source: src/ContentAudit/ContentAuditRow.php:42

- CR-122 — Pending · LOW
  - Finding: Logger silently drops file logging if mkdir/fopen fails -- no
    warning that var/logs output is missing.
  - Source: src/Support/Logger.php:43-51

- CR-123 — Pending · LOW
  - Finding: Missing-media locate() returns the first basename match when none
    ends with the relative path -- could restore the wrong same-named file.
    Prefer an "# AMBIGUOUS" comment listing candidates.
  - Source: src/Report/MissingMediaCopyScriptWriter.php:140-153

- CR-124 — Pending · LOW
  - Finding: PodsDetectionCheck '%pods%' table match also hits unrelated
    names containing the substring (e.g. wp3_podcasts).
  - Source: src/Audit/Checks/PodsDetectionCheck.php:39

- CR-125 — Pending · LOW
  - Finding: CliArguments "--key value" form swallows the next non-flag arg;
    a future positional argument would be eaten silently.
  - Source: src/Support/CliArguments.php:40-44

- CR-126 — Pending · LOW
  - Finding: pickRandomId loops forever if the effective range is a single
    value equal to oldId. Add a bounded retry or upfront check.
  - Source: src/Destination/AdminIdRenumberer.php:41-45

- CR-127 — Pending · LOW
  - Finding: Site::fromBlogRow mixes $override->x ?? y and $override?->y for
    the same nullable; harmless but inconsistent (the pattern PHPStan flags).
  - Source: src/Migration/Site.php:52,64-65

## Positive Observations (N/A)

- AuditRunner isolates check crashes into error findings instead of aborting
  the run (AuditRunner.php:40-49).
- SuppressionFilter never hides silently -- the audit.suppressed summary
  finding records that filtering happened (SuppressionFilter.php:52-61).
- PluginInventory correctly detects real plugins via "Plugin Name:" headers
  rather than trusting directory names -- kills vendor/ and dotfile noise.
- UploadsPathResolver handles both modern uploads/sites/{id}/ and legacy
  blogs.dir layouts with a documented candidate order.
- Word-boundary spam matching (PostScanner, MalwareHeuristics) already fixes
  the "cialis in specialist" false positive class.
- Config validation errors name the offending key and section; connection
  failures print actionable per-driver troubleshooting (Connection.php:74-98).
- MissingMediaCopyScriptWriter copies into the SOURCE tree deliberately so
  the migrator's own collision-rename still applies -- the subtle right call.


# Do After (deferred until after commit)

- **XLSX readability** — set a minimum 12pt font in
  `ContentAuditReportWriter::toXlsx()` (default PhpSpreadsheet font is 11pt). Important detailed information can be larger.

- **wp_navigation decision** — core post type for Site Editor block menus
  (each menu is one wp_navigation post of core/navigation-* blocks). It's real
  navigation content, so it stays in the audit for now; confirm rows are useful
  vs. noise once names are shown.

- **wp_template / wp_template_part visibility** — block-theme templates; headers
  and footers live in wp_template_part. Decide whether they appear in the XLSX
  (they're not page content, but they ARE the answer to "which site's header
  survives the merge").

- **Duplicate template-part slugs across sites** — nothing currently traps N
  sites each having a wp_template_part named 'header'. Add a check (probably
  in the integrity checker or the content audit) flagging same-slug
  wp_template/wp_template_part across sites, so the main-site template wins
  deliberately rather than by collision order.

- **"Main site" setting** — add e.g. 'main_site' => 20 to config.php
  (website-tech.glerner.com). Consumers: header/footer/template-part
  resolution (main site's wins), possibly category/name conflict defaults
  elsewhere. Post-merge, site identity elements come from the main site.
