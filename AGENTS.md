# merge-multisite — agent rules

For file/function/class lookup, see `docs/code-inventory.md`. For the
phased migration plan, see `PLAN.md`.

## Git

NEVER run `git commit` — the user commits by hand. When asked for a
commit, produce `commit-message.txt` (see the generate-commit-message
skill) and stop; the user runs the commit themselves.

## Environment

`lando` is at `~/.lando/bin/lando` and is NOT on the agent shell's PATH — add it to the PATH before using it, or
always invoke it by full path. Lando apps live outside this repo (e.g.
`~/sites/destination-wp`, `~/sites/wordpress`); `cd` there to run `lando wp`.
`*.lndo.site` URL problems (foreign Apache page, refused ports, drifted
`:8000`/`:443`) → see `docs/lando-cfce-runbook.md`.

## Verify

Always verify changes with: `composer test && composer phpcs && composer phpstan`
(tests, WordPress-standard PHPCS, PHPStan 2.x). All three must pass.
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
  `~/sites/phpunit-testing/docs/guides/ai-pair-programming-prompt.md`
  and
  `~/sites/wpwm-color-palette-generator/docs/code-review-prompt-template.md`.)
