<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Plans and applies a retag of stale wp_template/wp_template_part
 * rows on one site of the SOURCE multisite.
 *
 * A stale row is a template still owned by a dormant theme
 * (post_name "oldtheme//slug", wp_theme term = oldtheme) while the
 * site runs another theme. Pages that resolve to it render nothing.
 * Retagging the row -- not editing each page -- fixes every page
 * that resolves to that slug at once:
 *   1. post_name "oldtheme//slug" becomes "activetheme//slug".
 *   2. Its wp_theme term relationship points at the active theme.
 *   3. _wp_page_template meta rows that name the old slug follow.
 *
 * All reads and writes are plain SQL on the source database through
 * Connection -- no wp-cli, so it works from any directory whether
 * the source DB is Lando, Local, or plain MySQL.
 *
 * @package MergeMultisite
 */
final class TemplateRetagger {

	public function __construct(
		private readonly Connection $source,
		private readonly int $blogId,
	) {}

	/**
	 * The site's active stylesheet (theme directory name).
	 *
	 * @throws \RuntimeException When the stylesheet option is missing.
	 */
	public function stylesheet(): string {
		$options = $this->source->siteTable( 'options', $this->blogId );
		$value   = $this->source->fetchScalar(
			sprintf( 'SELECT option_value FROM %s WHERE option_name = :name', $options ),
			array( 'name' => 'stylesheet' )
		);

		if ( ! is_string( $value ) || $value === '' ) {
			throw new \RuntimeException(
				sprintf( 'No "stylesheet" option found in %s -- is --site=%d a real blog?', $options, $this->blogId )
			);
		}

		return $value;
	}

	/**
	 * The retag plan for this site.
	 *
	 * @return array{active: string,
	 *               operations: array<int, array{id: int, postType: string, slug: string,
	 *                                            oldName: string, newName: string,
	 *                                            activeExists: bool, metaRefs: int}>,
	 *               dormantGlobalStyles: array<int, array{id: int, name: string}>}
	 *         operations: one per stale row. activeExists means an
	 *         "activetheme//slug" row already exists, so retagging
	 *         creates a duplicate slug and the caller should force a
	 *         human decision. dormantGlobalStyles are dormant theme
	 *         customizations, not page templates -- report only.
	 */
	public function plan(): array {
		$active   = $this->stylesheet();
		$posts    = $this->source->siteTable( 'posts', $this->blogId );
		$postMeta = $this->source->siteTable( 'postmeta', $this->blogId );

		$rows = $this->source->fetchAll(
			sprintf(
				'SELECT ID, post_type, post_name FROM %s'
				. " WHERE post_type IN ('wp_template', 'wp_template_part')"
				. " AND post_name LIKE '%%//%%' AND post_name NOT LIKE :active"
				. ' ORDER BY post_type, post_name',
				$posts
			),
			array( 'active' => $active . '//%' )
		);

		$operations = array();
		foreach ( $rows as $row ) {
			$oldName = (string) $row['post_name'];
			$slug    = (string) substr( $oldName, strpos( $oldName, '//' ) + 2 );
			$newName = $active . '//' . $slug;

			$operations[] = array(
				'id'           => (int) $row['ID'],
				'postType'     => (string) $row['post_type'],
				'slug'         => $slug,
				'oldName'      => $oldName,
				'newName'      => $newName,
				'activeExists' => (int) $this->source->fetchScalar(
					sprintf(
						'SELECT COUNT(*) FROM %s WHERE post_type = :type AND post_name = :name',
						$posts
					),
					array(
						'type' => (string) $row['post_type'],
						'name' => $newName,
					)
				) > 0,
				'metaRefs'     => (int) $this->source->fetchScalar(
					sprintf(
						'SELECT COUNT(*) FROM %s WHERE meta_key = :key AND meta_value = :value',
						$postMeta
					),
					array(
						'key'   => '_wp_page_template',
						'value' => $oldName,
					)
				),
			);
		}

		$dormant = array_map(
			static fn ( array $row ) => array(
				'id'   => (int) $row['ID'],
				'name' => (string) $row['post_name'],
			),
			$this->source->fetchAll(
				sprintf(
					"SELECT ID, post_name FROM %s WHERE post_type = 'wp_global_styles'"
					. ' AND post_name <> :active ORDER BY post_name',
					$posts
				),
				array( 'active' => 'wp-global-styles-' . $active )
			)
		);

		return array(
			'active'               => $active,
			'operations'           => $operations,
			'dormantGlobalStyles'  => $dormant,
		);
	}

	/**
	 * Apply a set of plan operations inside one transaction.
	 *
	 * @param array<int, array{id: int, oldName: string, newName: string}> $operations
	 *        Entries from plan(), optionally filtered by the caller.
	 *
	 * @throws \Throwable Re-thrown after rollback.
	 */
	public function apply( array $operations ): void {
		$this->source->beginTransaction();

		try {
			$termTaxonomyId = $this->ensureThemeTerm( $this->stylesheet() );

			foreach ( $operations as $operation ) {
				$this->retagRow( $operation, $termTaxonomyId );
			}

			$this->source->commit();
		} catch ( \Throwable $exception ) {
			$this->source->rollBack();

			throw $exception;
		}
	}

	/**
	 * The wp_theme term_taxonomy_id for the active theme, creating the
	 * term and term_taxonomy rows when the theme has never customized
	 * templates on this site.
	 */
	private function ensureThemeTerm( string $theme ): int {
		$terms        = $this->source->siteTable( 'terms', $this->blogId );
		$termTaxonomy = $this->source->siteTable( 'term_taxonomy', $this->blogId );

		$existing = $this->source->fetchScalar(
			sprintf(
				'SELECT tt.term_taxonomy_id FROM %s tt'
				. ' JOIN %s t ON t.term_id = tt.term_id'
				. " WHERE tt.taxonomy = 'wp_theme' AND t.name = :theme",
				$termTaxonomy,
				$terms
			),
			array( 'theme' => $theme )
		);

		if ( $existing !== null ) {
			return (int) $existing;
		}

		$this->source->execute(
			sprintf( 'INSERT INTO %s (name, slug) VALUES (:name, :slug)', $terms ),
			array(
				'name' => $theme,
				'slug' => $theme,
			)
		);
		$termId = $this->source->lastInsertId();

		$this->source->execute(
			sprintf(
				"INSERT INTO %s (term_id, taxonomy, description, parent, count) VALUES (:id, 'wp_theme', '', 0, 0)",
				$termTaxonomy
			),
			array( 'id' => $termId )
		);

		return $this->source->lastInsertId();
	}

	/**
	 * Retag one row: rename post_name, move its wp_theme relationship
	 * to the active term, and rewrite _wp_page_template references.
	 *
	 * @param array{id: int, oldName: string, newName: string} $operation
	 */
	private function retagRow( array $operation, int $termTaxonomyId ): void {
		$posts         = $this->source->siteTable( 'posts', $this->blogId );
		$postMeta      = $this->source->siteTable( 'postmeta', $this->blogId );
		$termTaxonomy  = $this->source->siteTable( 'term_taxonomy', $this->blogId );
		$relationships = $this->source->siteTable( 'term_relationships', $this->blogId );

		$this->source->execute(
			sprintf( 'UPDATE %s SET post_name = :new WHERE ID = :id', $posts ),
			array(
				'new' => $operation['newName'],
				'id'  => $operation['id'],
			)
		);

		// Replace every wp_theme relationship with one to the active
		// term. Delete-then-insert avoids a duplicate-key error when the
		// row already also relates to the active term.
		$this->source->execute(
			sprintf(
				'DELETE FROM %s WHERE object_id = :id AND term_taxonomy_id IN'
				. " (SELECT term_taxonomy_id FROM %s WHERE taxonomy = 'wp_theme')",
				$relationships,
				$termTaxonomy
			),
			array( 'id' => $operation['id'] )
		);
		$this->source->insertIgnore(
			sprintf(
				'INSERT IGNORE INTO %s (object_id, term_taxonomy_id, term_order) VALUES (:id, :tt, 0)',
				$relationships
			),
			array(
				'id' => $operation['id'],
				'tt' => $termTaxonomyId,
			)
		);

		$this->source->execute(
			sprintf(
				'UPDATE %s SET meta_value = :new WHERE meta_key = :key AND meta_value = :old',
				$postMeta
			),
			array(
				'new' => $operation['newName'],
				'key' => '_wp_page_template',
				'old' => $operation['oldName'],
			)
		);
	}
}
