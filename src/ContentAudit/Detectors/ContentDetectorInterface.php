<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

use MergeMultisite\ContentAudit\ScannedPost;

/**
 * A small, focused rule that inspects one ScannedPost and reports
 * every match it finds for its category (e.g. "which form plugin(s)
 * appear on this page"). See PLAN.md §8.
 *
 * @package MergeMultisite
 */
interface ContentDetectorInterface {

	/**
	 * The CSV column / report category this detector fills in, e.g.
	 * "form_plugin", "page_builder", "blocks".
	 */
	public function category(): string;

	/**
	 * @return string[] Distinct labels detected on this post for this
	 *                  detector's category. Empty array if none found.
	 */
	public function detect( ScannedPost $post ): array;
}
