<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\TermMergeResolver;

/**
 * Previews the category/tag case-collision merge decisions the real
 * migration's TermMigrator will make (PLAN.md §6), before you commit
 * to running it, using the same TermMergeResolver logic.
 *
 * @package MergeMultisite
 */
final class TermCaseCollisionCheck implements AuditCheckInterface {

	private readonly TermMergeResolver $resolver;

	public function __construct( ?TermMergeResolver $resolver = null ) {
		$this->resolver = $resolver ?? new TermMergeResolver();
	}

	public function name(): string {
		return 'term-case-collision';
	}

	public function description(): string {
		return 'Category/tag labels that differ only by case; the migration merges them into one canonical label.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$candidates = array();

		foreach ( $sites as $site ) {
			$termsTable = $source->siteTable( 'terms', $site->blogId );
			$taxonomyTable = $source->siteTable( 'term_taxonomy', $site->blogId );

			$rows = $source->fetchAll(
				"SELECT t.name AS label, tt.taxonomy, tt.count AS usage_count
                 FROM {$termsTable} t
                 INNER JOIN {$taxonomyTable} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy IN ('category', 'post_tag')"
			);

			foreach ( $rows as $row ) {
				$candidates[] = array(
					'taxonomy' => (string) $row['taxonomy'],
					'label' => (string) $row['label'],
					'usage_count' => (int) $row['usage_count'],
					'site_id' => $site->blogId,
				);
			}
		}

		$groups = $this->resolver->resolve( $candidates );

		$findings = array();
		foreach ( $groups as $group ) {
			if ( ! $group->hasCaseCollision() ) {
				continue;
			}

			$findings[] = AuditFinding::info(
				$this->name(),
				sprintf(
					'Taxonomy "%s": %s merge into "%s".',
					$group->taxonomy,
					implode( ', ', array_map( static fn ( string $l ): string => '"' . $l . '"', $group->variantLabels ) ),
					$group->canonicalLabel
				),
				array(
					'taxonomy' => $group->taxonomy,
					'variants' => $group->variantLabels,
					'canonical' => $group->canonicalLabel,
				)
			);
		}

		return $findings;
	}
}
