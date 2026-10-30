# merge-multisite — agent rules

For file/function/class lookup, see `docs/code-inventory.md`. For the
phased migration plan, see `PLAN.md`. Coding standards shared across
George's PHP projects live outside this repo and apply here:

- `~/sites/phpunit-testing/docs/guides/ai-pair-programming-prompt.md`
  — workflow, docblock placement/style, test/fixture rules.
- `~/sites/wpwm-color-palette-generator/docs/code-review-prompt-template.md`
  — code-review checklist and the stop-and-reevaluate tripwires.

## Git

NEVER run `git commit` — the user commits by hand. When asked for a
commit, produce `commit-message.txt` (see the generate-commit-message
skill) and stop; the user runs the commit themselves.

## Secrets

`config/config.php` is SECRET — KEEP OUT. Never open, read, grep, or
edit it, not even to "check one value". It holds real credentials and
the admin identity. If a task needs a config value, read
`config/config.sample.php` or ask the user. Never copy any value from
it into committed files (tests, samples, docs, code). The other
gitignored real configs (`sites.php`, `option-keys.php`, etc.) may
also contain private site data — prefer the `*.sample.php` versions.

## Environment

`lando` is at `~/.lando/bin/lando` and is NOT on the agent shell's PATH — add it to the PATH before using it, or
always invoke it by full path. Lando apps live outside this repo (e.g.
`~/sites/destination-wp`, `~/sites/wordpress`); `cd` there to run `lando wp`.
`*.lndo.site` URL problems (foreign Apache page, refused ports, drifted
`:8000`/`:443`) → see `docs/lando-cfce-runbook.md`.

## Verify

Verify each SET of related changes (not every individual edit) with:
`composer test && composer phpcs && composer phpstan` (tests,
WordPress-standard PHPCS, PHPStan 2.x). All three must pass.
`composer test` runs plain Composer-installed PHPUnit — not the
`phpunit-testing` framework repo.

## Conventions

- PHP 8.2+, `declare(strict_types=1)`, WordPress array syntax
  (`array(...)`), docblocks on classes and public methods.
- Named arguments for any call with more than ~4 parameters, or any
  call where same-type arguments could silently swap (IDs, booleans,
  strings) — e.g. `new ScannedPost( blogId: 1, postId: 1, ... )`.
  Never write positional "mystery parameters".
- Config lives in `config/*.php` returning arrays; real files are
  gitignored, every one has a committed `*.sample.php`.
- Reports/logs go under `var/` (gitignored).
- WordPress data shapes: postmeta is `meta_key => array of values`;
  options may be serialized; network-active plugins live in
  `wp_sitemeta`, per-site plugins in `wp_{blogId}_options`.
- Keep output decision-oriented: group findings, suppress known-benign
  noise via config, never silently drop findings.
- No "mystery strings": a literal used in more than one place gets
  bound to a named variable/constant — easier to review and debug, and
  one instance can't be missed in an edit.
- Test/fixture data is invented placeholder data (e.g. "Pineapple
  Headphone"), never real names, logins, or emails — and never a value
  copied out of `config/config.php`.
- Docblock placement: scripts/config files get a file-level docblock
  before `declare`; class files get the class docblock after the
  `use` lines, immediately against the `final class` line (no blank
  line between).
- Docblocks and comments are plain English: one idea per sentence,
  one sentence per line. Never chain three or more facts with
  em-dashes or semicolons — split them into separate lines or a short
  bullet list. Each sentence must stand alone when read in isolation.
- When referencing a code-review finding (CR-xxx), always include the
  file name, e.g. `docs/code-review-2026-10-03.md` CR-402. There are
  several code-review files; a bare "CR-402" is ambiguous. Or don't
  reference it at all.
- If a fix attempt fails twice and the fix is growing more complex,
  stop: simplify the code or ask the user. Prefer the simplest change
  that matches existing patterns. (The fuller tripwire list lives in
  the standards docs above.)
