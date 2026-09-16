<?php

/**
 * Shortcode tag names the site-audit should NOT report.
 *
 * The ShortcodeDetector finds every [tag ...] in post_content, which
 * also catches bracketed English words in prose ("[the]", "[in]") and
 * keys from pasted PHP/config dumps ("[file]", "[blog_id]"). A small
 * built-in list covers the common ones; add your own here, one per
 * line, with or without square brackets:
 *
 *   'sitemap',
 *   '[my-legacy-thing]',
 *
 * Copy to config/shortcode-ignore.php (gitignored).
 */
return [
    // 'example-tag',
];
