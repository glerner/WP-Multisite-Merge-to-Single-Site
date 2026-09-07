<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Pure decision logic for merging categories/tags across sites that
 * differ only by case (e.g. "php" vs "PHP") -- see PLAN.md §6.
 *
 * Deliberately has no database dependency so it is fully unit-testable
 * in isolation; the audit check and (later) the real TermMigrator both
 * feed it the same shape of candidate data.
 *
 * @package MergeMultisite
 */
final class TermMergeResolver {

	/**
	 * @param array<int, array{taxonomy:string, label:string, usage_count:int, site_id:int}> $candidates
	 *
	 * @return array<string, TermMergeGroup> Keyed by "{taxonomy}|{normalized label}".
	 */
	public function resolve( array $candidates ): array {
		$groups = array();

		foreach ( $candidates as $candidate ) {
			$key = $this->normalizedKey( $candidate['taxonomy'], $candidate['label'] );
			$groups[ $key ][] = $candidate;
		}

		$result = array();
		foreach ( $groups as $key => $members ) {
			$result[ $key ] = $this->resolveGroup( $members );
		}

		return $result;
	}

	private function normalizedKey( string $taxonomy, string $label ): string {
		return $taxonomy . '|' . strtolower( trim( $label ) );
	}

	/**
	 * @param array<int, array{taxonomy:string, label:string, usage_count:int, site_id:int}> $members
	 */
	private function resolveGroup( array $members ): TermMergeGroup {
		// Usage count per distinct exact label (case-sensitive), summed
		// across every site that uses that exact variant.
		$usageByLabel = array();
		$firstSiteByLabel = array();

		foreach ( $members as $member ) {
			$label = $member['label'];
			$usageByLabel[ $label ] = ( $usageByLabel[ $label ] ?? 0 ) + $member['usage_count'];
			$firstSiteByLabel[ $label ] = min( $firstSiteByLabel[ $label ] ?? PHP_INT_MAX, $member['site_id'] );
		}

		// Most-used wins; ties broken by the label first encountered on
		// the lowest site_id (deterministic regardless of array order).
		$canonical = null;
		$bestUsage = -1;
		$bestFirstSite = PHP_INT_MAX;

		foreach ( $usageByLabel as $label => $usage ) {
			$firstSite = $firstSiteByLabel[ $label ];

			if (
				$usage > $bestUsage
				|| ( $usage === $bestUsage && $firstSite < $bestFirstSite )
			) {
				$canonical = $label;
				$bestUsage = $usage;
				$bestFirstSite = $firstSite;
			}
		}

		return new TermMergeGroup(
			taxonomy: $members[0]['taxonomy'],
			canonicalLabel: (string) $canonical,
			variantLabels: array_keys( $usageByLabel ),
			members: $members
		);
	}
}
