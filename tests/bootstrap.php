<?php

/**
 * PHPUnit bootstrap for the merge-multisite test suite.
 *
 * This is a small, self-contained bootstrap (no WordPress load, no
 * external test framework dependency) since none of this project's code
 * runs inside a WordPress process. See PLAN.md §3.1 for the rationale
 * for not depending on ~/sites/phpunit-testing here.
 *
 * @package MergeMultisite
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
