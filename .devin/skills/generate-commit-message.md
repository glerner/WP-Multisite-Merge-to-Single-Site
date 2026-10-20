---
description: Generate a commit message from git-diff.txt and write it to commit-message.txt
---

Read the entire contents of `git-diff.txt`. From those changes, make a git commit message.

Use the `git-diff.txt` the user already made. You can check that `git-diff.txt` is modified very recently; alert the user if not.

Save it to `commit-message.txt`, which already exists (do not read it first, replace the whole content).

Save it as plain text using a Markdown-like structure (blank lines and `-` bullet lists), but do not use any headings that start with `#` (because Git ignores lines starting with `#` in the commit editor). Mention file names as plain text only (no links).

Organize the message by Logical Changes rather than just listing files:

1. **Top-Level Summary**: Start with a single line that summarizes the whole commit.
2. **Logical Change Sections**: Group related changes under plain text section headers.
3. **Change Description**: Use a top-level bullet (`-`) to describe the logical change (e.g., "Refactor: Rename button variants" or "API: Add P3 metrics").
4. **File Lists**:
   - If a change affects multiple files in the same way, list the files as an indented sub-list or a comma-separated block.
   - If a file has unique, complex changes, use the `- filename:` format with indented sub-bullets for specific logic updates.

Wrap long lines at ~100 characters. Lines up to 120 are acceptable for URLs or code references that cannot be reasonably broken; continuation lines indent to align with the sub-bullet text start (4 spaces). Separate top-level bullets or sections with blank lines. Section headers are plain text lines with no prefix character (not `#`, not `-`). Leave a blank line before and after each section header.

Key rules:
- Only describe changes actually present in `git-diff.txt` — never invent changes.
- Do not run `git diff HEAD > git-diff.txt` unless the user asks — they may want a partial commit.
- Do not mention CR-* codes without naming the specific code review document they belong to.
- Do not mention temporary/debug code that was added and removed in the same session.
- Use clear plain-English descriptions, not jargon like "click-payload constructors".
- `.env.local` is gitignored; changes to it do not belong in commit messages.

## Writing to gitignored files

`commit-message.txt` is gitignored, so the `write_to_file` tool blocks access to it. To write to gitignored files, use `run_command` with a terminal redirect or heredoc instead:

```bash
cat > commit-message.txt << 'ENDOFFILE'
...content...
ENDOFFILE
```

This has worked reliably for months. Do NOT claim you "can't" edit gitignored files — just use the terminal.
