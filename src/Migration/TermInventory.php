<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Per-site inventory of terms + their term_taxonomy rows. Shared by
 * TermCaseCollisionCheck and site-audit.php (which need merge-decision
 * candidates) and TermMigrator (which needs the full rows to recreate
 * terms on the destination).
 *
 * @package MergeMultisite
 */
final class TermInventory {

	/**
	 * Fetches every term row joined to its taxonomy row per site.
	 *
	 * @param Site[]        $sites
	 * @param string[]|null $taxonomies Restrict to these taxonomies;
	 *                                  null means all taxonomies
	 *                                  (nav menus can reference any).
	 *
	 * @return array<int, array<int, array{term_id:string, name:string, slug:string, taxonomy:string, description:string, parent:string, count:string}>>
	 *         Rows keyed by blog_id, in site order.
	 */
	public function collect( Connection $source, array $sites, ?array $taxonomies = null ): array {
		$bySite = array();
		foreach ( $sites as $site ) {
			$termsTable = $source->siteTable( 'terms', $site->blogId );
			$taxonomyTable = $source->siteTable( 'term_taxonomy', $site->blogId );

			$params = array();
			$taxonomyClause = '';
			if ( $taxonomies !== null ) {
				$placeholders = array();
				foreach ( array_values( $taxonomies ) as $index => $taxonomy ) {
					$key = 'taxonomy_' . $index;
					$placeholders[] = ':' . $key;
					$params[ $key ] = $taxonomy;
				}
				$taxonomyClause = ' WHERE tt.taxonomy IN (' . implode( ', ', $placeholders ) . ')';
			}

			$bySite[ $site->blogId ] = $source->fetchAll(
				"SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.description, tt.parent, tt.count
                 FROM {$termsTable} t
                 INNER JOIN {$taxonomyTable} tt ON tt.term_id = t.term_id" . $taxonomyClause,
				$params
			);
		}

		return $bySite;
	}

	/**
	 * Flattens collected rows into the candidate shape
	 * TermMergeResolver::resolve() consumes.
	 *
	 * @param array<int, array<int, array{taxonomy:string, name:string, count:string}>> $bySite
	 *
	 * @return array<int, array{taxonomy:string, label:string, usage_count:int, site_id:int}>
	 */
	public function candidates( array $bySite ): array {
		$candidates = array();
		foreach ( $bySite as $blogId => $rows ) {
			foreach ( $rows as $row ) {
				$candidates[] = array(
					'taxonomy' => (string) $row['taxonomy'],
					'label' => (string) $row['name'],
					'usage_count' => (int) $row['count'],
					'site_id' => (int) $blogId,
				);
			}
		}

		return $candidates;
	}
}
