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
shot-scraper install - installs Playwright, Chrome for Testing, FFmpeg (playwright ffmpeg),


Alternative — plain pip:

```bash
pip install shot-scraper    # or: pip install --user shot-scraper
shot-scraper install
```

Other browsers if ever needed: `shot-scraper install -b firefox` (or `webkit`).

Upgrade later: `pipx upgrade shot-scraper`.

## Quick start

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
| `-w 1280` / `-h 800` | Viewport size |
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
- Cookie banners/widgets in the way? Hide them first:
  `--javascript 'document.querySelector(".cookie-banner")?.remove()'`
- Pages behind a login: `shot-scraper auth URL context.json` once,
  then `--auth context.json` on later shots.
- Videos of interactions exist too (`shot-scraper video` with a
  storyboard YAML: click/type/scroll/pause actions) — useful for
  training videos; see upstream docs.

## Project usage

- **merge-multisite**: `php bin/template-screenshots.php` screenshots
  every included site's header/footer plus each `wp_template` that
  resolves to a real page (front/posts-page resolution via
  `show_on_front`, WooCommerce page-ID options, sample posts per post
  type, synthetic 404/search URLs) into
  `var/reports/template-shots/{blogId}-{slug}.png` — files are
  overwritten each run. Template parts are shot on a page whose
  resolved template includes them; only `header`/`footer`/`sidebar`/
  `main` get element shots (block markup doesn't carry the part slug).
  Rows customized under an inactive theme are reported as retag
  candidates, not shot. `--site=N` limits to one site, `--dry-run`
  writes `shots.yml` without invoking shot-scraper. Planning lives in
  `src/Migration/TemplateShotPlan.php`; per-site option/theme-file
  gathering in `TemplateContextCollector.php`.
- **wpwm-color-palette-generator**: same CLI works for the Instructions
  tab screenshots; the pipx install is global, nothing project-local
  needed.
