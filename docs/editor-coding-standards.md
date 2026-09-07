# Editor coding-standards setup: what went wrong, and how it's fixed

This document exists because we hit a real, time-consuming problem while
setting up this project's coding standards, and it's worth writing down
so it doesn't happen again (here or in another project).

## The symptom

While building this project, the editor's live linter (red squiggly
lines) kept flagging almost every line of every file with WordPress
Coding Standards violations -- missing per-parameter docblocks, Yoda
conditions, snake_case naming, file-header comments, etc. -- even though
this project's own `phpcs.xml` had been deliberately written to *not*
require most of that (this is a standalone, PSR-4 autoloaded PHP tool,
not a WordPress plugin).

Every time the `phpcs.xml` ruleset was adjusted to quiet a category of
warnings, the editor kept showing the *same* warnings. That was the
signal something more fundamental was wrong, not the ruleset.

## Root cause: a global editor default, not a project setting

The editor extension responsible for the squiggles/formatting
(`valeryanm.vscode-phpsab`, the "PHP Sniffer & Beautifier" extension)
had a **global, user-level setting**:

```json
"phpsab.standard": "WordPress"
```

in `~/.config/Windsurf/User/settings.json` (and the equivalent VS Code
`settings.json`). This setting forces the extension to lint *and*
format-on-save *every* PHP file, in *every* project, using the literal
"WordPress" ruleset -- completely ignoring any project-local
`phpcs.xml`. It had presumably been set this way because most of this
author's other PHP projects are actual WordPress plugins/themes, where
that default made sense.

Because it's a global default rather than a per-project setting, it
silently applied to this project too, and no amount of editing this
project's own `phpcs.xml` could ever change what the editor was
actually checking against.

## What did NOT work (don't repeat this)

We spent a lot of back-and-forth revising `phpcs.xml` before finding
the actual cause:

1. **Excluding sniffs one by one in `phpcs.xml`.** Each round quieted
   `vendor/bin/phpcs` (the command-line check, which *does* read the
   project's `phpcs.xml`) but never touched the editor's live squiggles,
   because the editor wasn't reading that file at all. This looked like
   progress (the CLI tool's error count kept dropping) while the actual
   complaint (the editor) never changed -- a strong hint, in hindsight,
   that two different tools were involved.
2. **Assuming the CLI checker and the editor's linter must be looking
   at the same configuration.** They are two separate programs
   (`phpcs` invoked directly with `--standard=phpcs.xml`, vs. the
   `phpsab` VS Code/Windsurf extension invoking `phpcs`/`phpcbf` with
   whatever `phpsab.standard` resolves to). Nothing guarantees they
   agree unless both are explicitly told to use the same ruleset.
3. **Trying to satisfy the full "WordPress" ruleset's structural
   requirements piecemeal** (e.g. a script to auto-generate file-header
   docblocks) before confirming whether "WordPress" was even the right
   standard to be satisfying for this project in the first place.

## The actual fix

1. **Diagnose which tool is really producing the message.** The
   giveaway here was the sniff code in the squiggle's tooltip
   (`WordPress.Files.FileName.NotHyphenatedLowercase`) not matching
   anything `vendor/bin/phpcs` reported for that file -- meaning a
   *different* linter invocation was involved.
2. **Find the actual config source.**
   ```bash
   grep -rn '"phpsab\.' ~/.config/Windsurf/User/settings.json
   ```
   confirmed the global `"phpsab.standard": "WordPress"` default.
3. **Add a workspace-level override**, `.vscode/settings.json`, inside
   *this* project only:
   ```json
   {
       "phpsab.standard": "",
       "phpsab.snifferMode": "onSave",
       "phpcs.standard": "phpcs.xml"
   }
   ```
   Setting `phpsab.standard` to an empty string tells the extension to
   auto-detect a ruleset file (`phpcs.xml`, `phpcs.xml.dist`, etc.) in
   the project instead of forcing a named standard. VS Code/Windsurf
   merge workspace settings *on top of* user settings for whatever
   folder is open, so this only affects this project.
4. **Remove the global default**, since it was acting as an invisible
   fallback for every other PHP project too, rather than each project
   declaring what it actually wants. Removed
   `"phpsab.standard": "WordPress"` from the global
   `~/.config/Windsurf/User/settings.json`.
5. **Add the same explicit per-project override to every *other*
   PHP project that actually is WordPress code**, so removing the
   global default didn't silently change their linting:
   `.vscode/settings.json` with `"phpsab.standard": "WordPress"` in
   each of `phpunit-testing`, `option-change-logger`, `reinvent`,
   `website-tech`, `wpwm-cfce-plugin`, `wpwm-cfce-testing-temp`,
   `wpwm-color-scheme-toggle`, and `wpwm-theme-variation-display`.

## Takeaway for future projects

- A global editor default is fine as a *fallback*, but any project with
  requirements different from "most of my other projects" needs its
  own explicit workspace override -- don't rely on the global default
  happening to be right.
- If a linter/formatter's live feedback doesn't change after editing a
  project's own config file, stop editing that config file and check
  *which tool/config is actually running* first. Compare the sniff/rule
  codes reported by the CLI tool you control against what the editor is
  showing; a mismatch means you're looking at two different
  configurations, not one that needs more tuning.
- This project's own `phpcs.xml` intentionally does **not** enforce
  full WordPress Coding Standards (no forced `snake_case`, no Yoda
  conditions, no per-parameter prose docblocks) because it is a
  standalone, Composer/PSR-4 autoloaded PHP tool, not a WordPress
  plugin -- see `PLAN.md` §3 and §3.1 for why, and `phpcs.xml` itself
  for the specific, commented exclusions.
