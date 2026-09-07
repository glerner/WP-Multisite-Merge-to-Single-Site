<?php

/**
 * Sample manual category/tag canonicalization overrides.
 *
 * Copy to `term-overrides.php` (gitignored). Use this after reviewing
 * the term-merge report produced by a dry run, to correct any
 * case/label merge decision the automatic "most-used wins" rule (see
 * PLAN.md §6) got wrong for your data.
 *
 * Keys are matched case-insensitively against the original term label;
 * values are the canonical label to use on the destination site.
 *
 * @package MergeMultisite
 */

return [
    'taxonomy' => [
        'category' => [
            // 'php' => 'PHP',
        ],
        'post_tag' => [
            // 'msr' => 'MSR',
        ],
    ],
];
