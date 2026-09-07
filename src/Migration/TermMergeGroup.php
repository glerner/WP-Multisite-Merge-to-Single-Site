<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * The result of resolving one group of same-taxonomy, case-insensitive
 * duplicate terms down to a single canonical label.
 *
 * @package MergeMultisite
 */
final class TermMergeGroup {

	/**
	 * @param string                                                                         $taxonomy       e.g. "category" or "post_tag".
	 * @param string                                                                         $canonicalLabel The label chosen to represent this group on the destination.
	 * @param string[]                                                                       $variantLabels  Every distinct exact-case label seen across included sites.
	 * @param array<int, array{taxonomy:string, label:string, usage_count:int, site_id:int}> $members
	 */
	public function __construct(
		public readonly string $taxonomy,
		public readonly string $canonicalLabel,
		public readonly array $variantLabels,
		public readonly array $members,
	) {
	}

	/**
	 * Whether this group actually contains more than one distinct
	 * case/label variant (i.e. a real merge decision was made).
	 */
	public function hasCaseCollision(): bool {
		return count( $this->variantLabels ) > 1;
	}
}
