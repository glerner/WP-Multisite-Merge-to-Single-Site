# Making screenshots with shot-scraper

Automated website screenshots — whole pages or individual DOM elements —
from the command line. Used in this project to capture each site's
header/footer/template parts for merge decisions (output goes to
`var/reports/`), and reusable for training pages, videos, and
documenting any website (not just your own projects).

Upstream docs: <https://shot-scraper.datasette.io/en/stable/>

## Installation

Two steps: install the CLI, then install the Playwright Chromium it drives.

Recommended — `pipx` (isolated venv, `shot-scraper` on PATH for every
project; this is how it's installed on this machine):

```bash
pipx install shot-scraper
shot-scraper install        # one-time: downloads Playwright's Chromium (~115 MB)
```

You can ignore Linux Mint "BEWARE: your OS is not officially supported by Playwright; downloading fallback build for ubuntu22.04-x64."
`shot-scraper install` installs Playwright, Chrome for Testing, FFmpeg (playwright ffmpeg).


Alternative — plain pip:

```bash
pip install shot-scraper    # or: pip install --user shot-scraper
shot-scraper install
```

Other browsers if ever needed: `shot-scraper install -b firefox` (or `webkit`).

Upgrade later: `pipx upgrade shot-scraper`.

## Quick start

Run from whatever folder you want the output in — `-o` (and `--auth`)
paths are relative to the current directory; a `-o` with a path writes
there regardless of where you run it. Without `-o` it writes
`<host>.png` into the current directory.

```bash
# Whole page, 900px tall viewport
shot-scraper https://example.com -h 900 -o page.png

# Local HTML file
shot-scraper ./index.html -o page.png

# One DOM element — the reason we installed it
shot-scraper https://example.com --selector footer -o footer.png
```

## Capturing elements

| Flag | What it captures |
|---|---|
| `--selector "footer"` | First element matching the CSS selector |
| `--selector a --selector .x` | Smallest box containing ALL listed elements |
| `--selector-all ".card"` | Every match, one image per element |
| `--js-selector 'expr'` | Element picked by a JS expression over `el` |
| `--padding 20` | Pixels of padding around the element box (buggy: moves the recording area, doesn't add padding around the recorded area)|
| `--retina` | 2x resolution (sharp for docs/slides) |
| `-w 1280` / `-h 800` | Viewport size; `-h` defaults to the full page height, so whole-page shots need no flag |
| `--wait 500` | ms to wait after load (menus, animations) |
| `--wait-for 'document.querySelector("img.hero")'` | Wait for a JS condition |
| `--quality 80` | JPEG quality (switch extension to `.jpg` or `.webp`) |

Examples:

```bash
# Footer of a site, retina, with breathing room
shot-scraper https://website-tech.glerner.com --selector "footer" --retina --padding 8 -o site20-footer.png

# Header nav only, waiting for it to render
shot-scraper https://example.com --selector "header" --wait-for 'document.querySelector("header nav")' -o header.png
```

## Series of elements — `shot-scraper multi` + YAML

For "every site's header and footer" write a `shots.yml`:

```yaml
- output: var/reports/site20-header.png
  url: https://website-tech.glerner.com/
  selector: "header"
  retina: true

- output: var/reports/site20-footer.png
  url: https://website-tech.glerner.com/
  selector: "footer"
  retina: true

- output: var/reports/site58-footer.png
  url: https://computerhelp.lc.lndo.site/
  selectors:            # capture ALL matching elements in one image
    - "footer"
    - ".site-footer"
```

Then:

```bash
shot-scraper multi shots.yml          # all shots
shot-scraper multi shots.yml -n       # skip files that already exist
shot-scraper multi shots.yml -o site20-footer.png   # just this one
```

Per-shot keys available: `url`, `output`, `selector`, `selectors`,
`selector_all`, `js_selector`, `javascript` (runs after load, before the
shot — e.g. dismiss a banner), `js_file`, `width`, `height`, `quality`,
`wait`, `wait_for`, `retina`, `format` (skip `padding` — see the bug
note above). A `server:` block can
start a local server for the session; `sh:`/`python:` steps run between
shots.

## Tips

- Selector not matching? Dump the element's HTML to check:
  `shot-scraper html URL --selector footer`
- **Element shot cut off / blank tail (CFCE and other scroll-container
  apps)**: the app's scroll region is an inner div (`.tabsColumn` is
  `overflow-y:auto` inside `height:100dvh`), not the page — content
  scrolled out of that scroller is clipped and captures as blank
  background, so an element taller than the viewport can never fully
  render. Fix: give shot-scraper a viewport taller than the element
  (`-h 1600` or more; the element must fit inside the scroller height,
  not just the window). `locator.screenshot()`/`selector:` shots produce
  a full-height image but the off-scroller part is blank — check the
  bottom rows of the output.
- **Unexpected space/border at the top of an element shot**: CFCE's tab
  bar is `position: static` and does NOT overlay captures — verified by
  pixel-identical screenshots with it hidden vs. visible. If a top gap
  appears, suspect a mid-capture layout shift (e.g. an auth banner
  appearing/hiding while the clip is measured). A cheap safeguard on
  capture targets is `scroll-margin-top` (CFCE sets it on `[data-shot]`
  elements so scrolled-to targets land clear of the tab bar). If a real
  sticky/fixed bar ever does overlay a capture, hide it without changing
  layout —
  `--javascript 'document.querySelector("[role=tablist]").style.visibility="hidden"'`
  (`visibility:hidden` keeps layout; `display:none` shifts content up) —
  but note hidden elements can no longer be clicked, so toggle only
  around the screenshot step in storyboards.
- **Stable capture targets**: CSS-module hash suffixes
  (`._section_1krcm_1`, `._tabsList_b2yun_2`) change every build/deploy —
  don't rely on them in saved selectors. Either add a dedicated
  `data-shot="descriptive-word"` attribute, e.g.
  `data-shot="palette"` (preferred — clearly an automation
  hook, never styled) or a plain stable class, and select that. A
  quick-and-dirty alternative that survives hash changes: substring
  selectors like `[class*="_tabsList"]`.
  Example:
  `shot-scraper https://cfce.lndo.site/generator --selector [data-shot="palette"] --interactive --auth auth.json -w 1024 -h 1600 -o styles/fiery-ice-cream-delight-psta.png`
  (`-h` matters — see the nested-scroller note above). CFCE capture
  targets: `[data-shot="palette"]` (whole View Palette section),
  `brand-colors`, `message-colors`, and `adjust-{primary|secondary|
  tertiary|accent|error|notice|success}` (one role's four ribbons).
- For apps with tabbed UIs (CFCE): the target element doesn't exist in the
  DOM until its tab is active — click the tab first via `--javascript` /
  `js:` / a `click:` scene step, e.g.
  `[role="tab"]:has-text("View Palette")` (prefer role/text over Radix's
  auto-generated `radix-:r1:` ids, which change per session).
- `shot-scraper video` storyboards can emit stills too: a `screenshot:`
  step in a scene's `do:` list accepts `selector:` for element shots, so
  one storyboard can produce a walkthrough video AND the individual
  screenshots. `--auth` works on `video` as on other commands.
- Cookie banners/widgets in the way? Hide them first:
  `--javascript 'document.querySelector(".cookie-banner")?.remove()'`
- Pages behind a login (e.g. CFCE): run
  `shot-scraper auth https://cfce.lndo.site/generator auth.json` once.
  It opens a dedicated browser window — sign in there, do a Settings
  Import (Starting Colors → "Import Full Palette Settings", paste the
  Compact Token) so the palette is in localStorage, THEN hit Enter back
  in the terminal to save. The cookies/session AND localStorage are
  saved to `auth.json`; pass `--auth auth.json` on later shots (works
  in `shots.yml` too via the `auth:` key). Re-run `auth` when the
  session expires.
- Getting the right palette into the shot-scraper browser (CFCE):
  `shot-scraper auth` saves cookies AND localStorage (Playwright storage
  state), and CFCE persists the palette in localStorage — so an
  `auth.json` written after a palette session already carries that
  palette; no import needed for palette-tab shots. To load a *specific*
  palette hands-free: save the Compact Token export (Export tab →
  "Export Settings as Compact Token") to a file in the app's `public/`
  dir and load `https://cfce.lndo.site/generator?import=<filename>` —
  the app fetches `/filename` and runs the full-settings import on load
  (after the hydration transaction settles). This is the one-step flow
  for per-variation theme images: `?import=settings-token.txt` reads
  `public/settings-token.txt`. Note: `?import=` is a **URL** path, not a
  filesystem path — a local path like `/home/user/...` fetches the SPA
  fallback and fails with "not a token file (got HTML fallback)".
  Example: save the token as
  `~/sites/wpwm-color-palette-generator/public/fiery-ice-cream-delight-settings.txt`
  and load `?import=fiery-ice-cream-delight-settings.txt`. No `cd`
  needed — `?import=` is fetched over HTTP from the app origin; the
  shell's working directory only affects where relative `-o`/`--auth`
  paths resolve.
  Token files must be in the App Origin's public/ folder.
  Manual alternative: in `--interactive` mode, paste the token into
  Starting Colors → "Import Full Palette Settings".
- Videos of interactions exist too (`shot-scraper video` with a
  storyboard YAML: click/type/scroll/pause actions) — useful for
  training videos; see upstream docs. Playwright recordings have no
  real cursor; the storyboard `cursor:` key injects one. It's
  configurable: `cursor: {visible: true, clicks: true,
  color: "#ff4f00", size: 18, click_size: 44}` — one color covers both
  the dot and the click ring (you can't split colors), but the ring can
  be a different size. Set `visible: false, clicks: true` for click
  rings without a resting dot.

## Project usage

### merge-multisite: template screenshots

`php bin/template-screenshots.php` produces one PNG per site template
for visual comparison across the multisite during merge decisions:
`var/reports/template-shots/{blogId}-{slug}.png`, overwritten each
run. A `shots.yml` is written alongside and the run logs to
`var/logs/template-screenshots-<timestamp>.log`.

**What gets a shot** — planned in `src/Migration/TemplateShotPlan.php`,
per-site option/theme-file gathering in `TemplateContextCollector.php`:

- Always: the site's `header` and `footer` element shots, even with no
  DB row (classic themes render them regardless).
- Each `wp_template`/`wp_template_part` row only if it's `publish` and
  tagged with the site's *active* theme.
- Templates that exist only as `templates/*.html` theme files are shot
  too — they render even without a DB row.
- Synthetic pages for templates with no natural URL: `404` shots a
  guaranteed-missing path, `search` shots `?s=merge-multisite-template-probe`.

**What gets skipped instead** — each with a logged reason:

- Draft rows; plugin-tagged parts (`surecart/surecart`,
  `woocommerce/woocommerce`) with no resolvable page; plugin templates
  resolve only via that plugin's page-ID options, never the theme
  hierarchy.
- Parts with no mappable selector: block markup doesn't include the
  part slug, so only `header`, `footer`, `sidebar`, `main` can be
  found in the DOM (`comments`, `post-meta`, etc. are skipped).
- Rows customized under an inactive theme — reported as *retag
  candidates* (retag the row to the active stylesheet to restore),
  never shot.
- A `template`-option vs theme-header mismatch logs a warning; the
  theme header wins.

**Where each shot points** — a template is shot on a page that actually
renders it: `show_on_front`/`page_for_posts` resolution for the front
and posts pages, sample posts per post type, WooCommerce page IDs. A
part shot goes to the front page if the resolved front template
includes it, else the shot URL of the first template that does.

**Parameters:** `--site=<blog_id>` limits to one site; `--dry-run`
writes `shots.yml` without invoking shot-scraper; `--config=<dir>`
overrides the config directory.

**Shot mechanics** — same flags in the CLI calls and the emitted
`shots.yml`, so `shot-scraper multi shots.yml` replays identically:

- Every shot: `--retina`, `--wait 600`, and
  `--wait-for 'document.fonts.status === "loaded"'` — settle
  webfonts/lazy media so the capture isn't mid-layout-shift.
- Element shots also get
  - `-h 2400` (themes with an inner scroll
  container clip content that gets scrolled out of view — the element must fit
  inside the scroller height, see the nested-scroller note in Tips),
  - `--timeout 10000` (a part that renders empty/hidden would otherwise
  burn 30s in Playwright's stability wait), and
  - `--javascript`
  overlay guard that scrolls the target into view and hides
  `position:fixed`/`sticky` elements overlapping it — floating
  headers, cookie bars, back-to-top buttons would otherwise paint into
  the clip.
  - Elements inside or containing the target are kept, so a
  sticky nav inside a header stays in the shot.
- One `shot-scraper` invocation per entry: a missing element fails
  only that shot, not the batch.

**Gotcha — PHP notices in the images:** with `WP_DEBUG` on, plugins
that emit warnings can leak them into the page output — and into
screenshots — even with `WP_DEBUG_DISPLAY` false. `@ini_set( 'display_errors', 0 );` in
`wp-config.php` keeps the rendered pages (and captures) clean.

### wpwm-color-palette-generator

Same CLI works for the Instructions tab screenshots and palette
captures; the pipx install is global, nothing project-local needed.
See the Tips section for CFCE-specific selectors (`[data-shot="…"]`),
the auth/localStorage flow, and `?import=` token loading.
