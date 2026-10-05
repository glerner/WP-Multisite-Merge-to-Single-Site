<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Migrates categories, tags, and custom taxonomy terms from included subsites
 * into the destination single site (PLAN.md §6).
 *
 * Execution steps:
 * 1. Creates a top-level Category on the destination for each included subsite
 *    (named after the site's title/override) and records its term_taxonomy_id in
 *    IdMap ('site_category') so PostMigrator can assign it to migrated posts.
 * 2. Gathers term and term_taxonomy rows across all included sites via TermInventory.
 * 3. Resolves cross-site case collisions (e.g. 'php' vs 'PHP') via TermMergeResolver,
 *    applying any manual overrides from config/term-overrides.php.
 * 4. Inserts missing terms and taxonomy records into destination wp_terms and
 *    wp_term_taxonomy (or reuses matching existing destination terms), recording
 *    source (siteId, oldId) => destId mappings in IdMap and MigrationTable.
 * 5. Fixes hierarchical category parent-child relationships in a second pass.
 *
 * @package MergeMultisite
 */
final class TermMigrator {

	public function __construct(
		private readonly TermInventory $inventory = new TermInventory(),
		private readonly ?TermMergeResolver $resolver = null
	) {
	}

	/**
	 * Runs the term migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return array{
	 *     site_categories: array<int, array{term_id: int, term_taxonomy_id: int, name: string}>,
	 *     terms_migrated: int,
	 *     terms_reused: int,
	 *     case_collisions_merged: int,
	 *     parent_relationships_fixed: int,
	 *     groups: array<string, TermMergeGroup>
	 * }
	 *
	 * @throws \Throwable When destination database writes fail (rolled back first).
	 */
	public function migrate(
		Connection $source,
		Connection $destination,
		MergeConfig $config,
		array $sites,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		Logger $logger
	): array {
		$resolver = $this->resolver ?? new TermMergeResolver( $config->mainSite );

		$destTermsTable    = $destination->siteTable( 'terms', 1 );
		$destTaxonomyTable = $destination->siteTable( 'term_taxonomy', 1 );

		// Step 1: Pre-fetch existing destination terms so we reuse identical terms.
		$destExisting = $this->fetchDestinationTerms( $destination, $destTermsTable, $destTaxonomyTable );

		// Step 2: Collect source terms across included sites and resolve merge groups.
		$bySite     = $this->inventory->collect( $source, $sites );
		$candidates = $this->inventory->candidates( $bySite );
		$groups     = $resolver->resolve( $candidates );

		$report = array(
			'site_categories'            => array(),
			'terms_migrated'             => 0,
			'terms_reused'               => 0,
			'case_collisions_merged'     => 0,
			'parent_relationships_fixed' => 0,
			'groups'                     => $groups,
		);

		if ( ! $dryRun ) {
			$destination->beginTransaction();
		}

		try {
			// Step 3: Create or link top-level site category for each included site.
			foreach ( $sites as $site ) {
				$catName = $site->categoryName;
				$catSlug = $site->categorySlug ?? self::slugify( $catName );
				$catKey  = 'category|' . strtolower( trim( $catName ) );

				$existing = $destExisting[ $catKey ] ?? null;
				if ( $existing !== null ) {
					$destTermId = $existing['term_id'];
					$destTtId   = $existing['term_taxonomy_id'];
				} else {
					$destTermId = 0;
					$destTtId   = 0;
					if ( ! $dryRun ) {
						$destination->execute(
							"INSERT INTO {$destTermsTable} (name, slug, term_group) VALUES (:name, :slug, 0)",
							array(
								'name' => $catName,
								'slug' => $catSlug,
							)
						);
						$destTermId = (int) $destination->lastInsertId();

						$destination->execute(
							"INSERT INTO {$destTaxonomyTable} (term_id, taxonomy, description, parent, count) VALUES (:term_id, 'category', :desc, 0, 0)",
							array(
								'term_id' => $destTermId,
								'desc'    => $this->categoryDescription( $site ),
							)
						);
						$destTtId = (int) $destination->lastInsertId();
					}

					$destExisting[ $catKey ] = array(
						'term_id'          => $destTermId,
						'term_taxonomy_id' => $destTtId,
						'parent'           => 0,
						'name'             => $catName,
						'slug'             => $catSlug,
					);
				}

				$idMap->set( 'site_category', $site->blogId, 'category', $destTtId );
				$idMap->set( 'site_category_term', $site->blogId, 'category', $destTermId );

				if ( ! $dryRun ) {
					$mapTable->record( $destination, 'site_category', $site->blogId, 'category', $destTtId );
					$mapTable->record( $destination, 'site_category_term', $site->blogId, 'category', $destTermId );
				}

				$report['site_categories'][ $site->blogId ] = array(
					'term_id'          => $destTermId,
					'term_taxonomy_id' => $destTtId,
					'name'             => $catName,
				);
			}

			// Step 4: Migrate source terms using resolved canonical labels and overrides.
			foreach ( $groups as $groupKey => $group ) {
				$taxonomy       = $group->taxonomy;
				$canonicalLabel = $this->applyManualOverride( $taxonomy, $group->canonicalLabel, $config->termOverrides );
				$lookupKey      = $taxonomy . '|' . strtolower( trim( $canonicalLabel ) );

				if ( $group->hasCaseCollision() ) {
					++$report['case_collisions_merged'];
				}

				$existing = $destExisting[ $lookupKey ] ?? null;
				if ( $existing !== null ) {
					$destTermId = $existing['term_id'];
					$destTtId   = $existing['term_taxonomy_id'];
					++$report['terms_reused'];
				} else {
					$destTermId = 0;
					$destTtId   = 0;
					if ( ! $dryRun ) {
						$canonicalSlug = self::slugify( $canonicalLabel );
						$destination->execute(
							"INSERT INTO {$destTermsTable} (name, slug, term_group) VALUES (:name, :slug, 0)",
							array(
								'name' => $canonicalLabel,
								'slug' => $canonicalSlug,
							)
						);
						$destTermId = (int) $destination->lastInsertId();

						$destination->execute(
							"INSERT INTO {$destTaxonomyTable} (term_id, taxonomy, description, parent, count) VALUES (:term_id, :taxonomy, '', 0, 0)",
							array(
								'term_id'  => $destTermId,
								'taxonomy' => $taxonomy,
							)
						);
						$destTtId = (int) $destination->lastInsertId();
					}

					$destExisting[ $lookupKey ] = array(
						'term_id'          => $destTermId,
						'term_taxonomy_id' => $destTtId,
						'parent'           => 0,
						'name'             => $canonicalLabel,
						'slug'             => self::slugify( $canonicalLabel ),
					);
					++$report['terms_migrated'];
				}

				// Map every source term row in this group from its source site.
				foreach ( $sites as $site ) {
					$siteRows = $bySite[ $site->blogId ] ?? array();
					foreach ( $siteRows as $sourceRow ) {
						if (
							$sourceRow['taxonomy'] === $taxonomy
							&& strtolower( trim( (string) $sourceRow['name'] ) ) === strtolower( trim( $group->canonicalLabel ) )
						) {
							$oldTermId = (int) $sourceRow['term_id'];
							$idMap->set( 'term', $site->blogId, $oldTermId, $destTermId );
							$idMap->set( 'term_taxonomy', $site->blogId, $oldTermId, $destTtId );

							if ( ! $dryRun ) {
								$mapTable->record( $destination, 'term', $site->blogId, $oldTermId, $destTermId );
								$mapTable->record( $destination, 'term_taxonomy', $site->blogId, $oldTermId, $destTtId );
							}
						}
					}
				}

				// Record canonical-level key in IdMap for direct canonical lookups.
				$idMap->set( 'term', 0, $groupKey, $destTermId );
				$idMap->set( 'term_taxonomy', 0, $groupKey, $destTtId );
			}

			// Step 5: Hierarchical category parent-child fixup.
			foreach ( $sites as $site ) {
				$siteRows = $bySite[ $site->blogId ] ?? array();
				foreach ( $siteRows as $sourceRow ) {
					$oldParent = (int) $sourceRow['parent'];
					if ( $oldParent <= 0 ) {
						continue;
					}

					$newTermId   = $idMap->get( 'term', $site->blogId, (int) $sourceRow['term_id'] );
					$newParentId = $idMap->get( 'term', $site->blogId, $oldParent );

					if ( $newTermId !== null && $newParentId !== null && ! $dryRun ) {
						$destination->execute(
							"UPDATE {$destTaxonomyTable} SET parent = :parent WHERE term_id = :term_id AND taxonomy = :taxonomy",
							array(
								'parent'   => $newParentId,
								'term_id'  => $newTermId,
								'taxonomy' => (string) $sourceRow['taxonomy'],
							)
						);
						++$report['parent_relationships_fixed'];
					}
				}
			}

			if ( ! $dryRun ) {
				$destination->commit();
			}
		} catch ( \Throwable $e ) {
			if ( ! $dryRun ) {
				$destination->rollBack();
			}
			$logger->error( sprintf( 'Term migration failed, rolled back: %s', $e->getMessage() ) );
			throw $e;
		}

		$logger->info(
			sprintf(
				'Term migration complete: %d created, %d reused, %d case collisions merged, %d parent fixups.',
				$report['terms_migrated'],
				$report['terms_reused'],
				$report['case_collisions_merged'],
				$report['parent_relationships_fixed']
			)
		);

		return $report;
	}

	/**
	 * Sanitizes a title string into a clean lowercase URL slug.
	 */
	public static function slugify( string $text ): string {
		$text = strtolower( trim( $text ) );
		$text = str_replace( '_', '-', $text );
		$text = (string) preg_replace( '/[^a-z0-9-]+/', '-', $text );
		$text = (string) preg_replace( '/-+/', '-', $text );

		return trim( $text, '-' );
	}

	/**
	 * The destination category's description: the site's full title
	 * (its `blogname`) so nothing is lost even when the category name
	 * is a short override, plus the source domain for provenance.
	 */
	private function categoryDescription( Site $site ): string {
		$title = trim( $site->title );

		if ( $title !== '' && $title !== $site->categoryName ) {
			return sprintf( 'Migrated content from %s (%s)', $site->domain, $title );
		}

		return sprintf( 'Migrated content from %s', $site->domain );
	}

	/**
	 * Pre-fetches existing terms on destination to allow instant in-memory matching.
	 *
	 * @return array<string, array{term_id: int, term_taxonomy_id: int, parent: int, name: string, slug: string}>
	 */
	private function fetchDestinationTerms( Connection $destination, string $termsTable, string $taxonomyTable ): array {
		$map = array();
		if ( ! $destination->tableExists( $termsTable ) || ! $destination->tableExists( $taxonomyTable ) ) {
			return $map;
		}

		$rows = $destination->fetchAll(
			"SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.parent
             FROM {$termsTable} t
             INNER JOIN {$taxonomyTable} tt ON tt.term_id = t.term_id"
		);

		foreach ( $rows as $row ) {
			$key = (string) $row['taxonomy'] . '|' . strtolower( trim( (string) $row['name'] ) );
			$map[ $key ] = array(
				'term_id'          => (int) $row['term_id'],
				'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
				'parent'           => (int) $row['parent'],
				'name'             => (string) $row['name'],
				'slug'             => (string) $row['slug'],
			);
		}

		return $map;
	}

	/**
	 * Applies configured manual term overrides if present.
	 *
	 * @param array<string, array<string, string>> $termOverrides
	 */
	private function applyManualOverride( string $taxonomy, string $label, array $termOverrides ): string {
		$taxOverrides = $termOverrides[ $taxonomy ] ?? array();
		foreach ( $taxOverrides as $oldPattern => $canonical ) {
			if ( strtolower( trim( $oldPattern ) ) === strtolower( trim( $label ) ) ) {
				return $canonical;
			}
		}

		return $label;
	}
}
