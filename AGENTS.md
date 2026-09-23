# merge-multisite — agent rules

For file/function/class lookup, see `docs/code-inventory.md`. For the
phased migration plan, see `PLAN.md`.

## Git

NEVER run `git commit` — the user commits by hand. When asked for a
commit, produce `commit-message.txt` (see the generate-commit-message
skill) and stop; the user runs the commit themselves.

## Verify

Always verify changes with: `composer test && composer phpcs && composer phpstan`
(tests, WordPress-standard PHPCS, PHPStan 2.x). All three must pass.

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
