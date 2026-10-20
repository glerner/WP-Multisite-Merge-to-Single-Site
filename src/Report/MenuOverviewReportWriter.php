<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

/**
 * Renders the per-site nav-menu structure collected by MenuMigrator
 * (PLAN.md §7.5) into a manual-rebuild reference.
 * Output is `menus-overview.md`: a human-readable tree with labels,
 * targets, and nesting.
 * Output is also `menus-overview.json`: the same data,
 * machine-readable.
 * Pure formatting, no DB, fully unit-testable.
 *
 * Input shape (the `overview` section of MenuMigrator's report):
 *
 *     array<int, array{
 *         domain: string,
 *         menus: array<int, array{
 *             name: string,
 *             items: array<int, array{
 *                 id: int, label: string, type: string, object: string,
 *                 target: string, parent: int, menu_order: int
 *             }>
 *         }>,
 *         orphaned_items: array<int, array{
 *             id: int, label: string, type: string, object: string,
 *             target: string, parent: int, menu_order: int
 *         }>
 *     }>
 *
 * Items use SOURCE IDs.
 * Menu items are intentionally not wired to any theme location on the
 * destination, so there is no destination ID to show.
 * The tree is the review aid for rebuilding one clean destination menu
 * by hand.
 *
 * @package MergeMultisite
 */
final class MenuOverviewReportWriter {

	/**
	 * Build the nested item tree.
	 * Roots are items whose parent is 0 or not itself an item in the
	 * list.
	 * Children are grouped by parent.
	 * Siblings sort by menu_order, then source ID.
	 * That matches the source admin's ordering.
	 *
	 * @param array<int, array{id: int, label: string, type: string, object: string, target: string, parent: int, menu_order: int}> $items
	 *
	 * @return array<int, array{item: array<string, mixed>, children: array}>
	 */
	public function tree( array $items ): array {
		$byId = array();
		foreach ( $items as $item ) {
			$byId[ (int) $item['id'] ] = $item;
		}

		$children = array();
		foreach ( $items as $item ) {
			$parent = (int) $item['parent'];
			$children[ $parent > 0 && isset( $byId[ $parent ] ) ? $parent : 0 ][] = $item;
		}

		$sort = static function ( array &$nodes ): void {
			usort(
				$nodes,
				static function ( array $a, array $b ): int {
					$byOrder = (int) $a['menu_order'] <=> (int) $b['menu_order'];

					return $byOrder !== 0 ? $byOrder : ( (int) $a['id'] <=> (int) $b['id'] );
				}
			);
		};

		$build = function ( int $parentId ) use ( &$build, $children, $sort ): array {
			$nodes = $children[ $parentId ] ?? array();
			$sort( $nodes );

			$result = array();
			foreach ( $nodes as $item ) {
				$result[] = array(
					'item' => $item,
					'children' => $build( (int) $item['id'] ),
				);
			}

			return $result;
		};

		return $build( 0 );
	}

	/**
	 * Human-readable Markdown overview: one `## Site` section per
	 * included site, one `### Menu` tree per nav menu (plus an
	 * "Orphaned items" tree for unassigned items).
	 *
	 * @param array<int, array<string, mixed>> $overview
	 */
	public function toMarkdown( array $overview ): string {
		$lines = array( '# Menus overview', '', 'Nav menus migrated as inactive/orphaned data (PLAN.md §7.5). Each site\'s menus, their items, and the item nesting are listed here as the manual-rebuild reference for one clean destination menu. IDs are source IDs.' );

		foreach ( $overview as $blogId => $site ) {
			$lines[] = '';
			$lines[] = sprintf( '## Site %d — %s', $blogId, (string) $site['domain'] );

			$menus = $site['menus'];
			if ( $menus === array() ) {
				$lines[] = '';
				$lines[] = '_No menus._';
			}

			foreach ( $menus as $menu ) {
				$lines[] = '';
				$lines[] = sprintf( '### Menu: %s', (string) $menu['name'] );
				if ( $menu['items'] === array() ) {
					$lines[] = '';
					$lines[] = '_Empty menu._';
					continue;
				}
				$this->appendTree( $lines, $this->tree( $menu['items'] ) );
			}

			$orphaned = $site['orphaned_items'];
			if ( $orphaned !== array() ) {
				$lines[] = '';
				$lines[] = '### Orphaned items (not assigned to any menu)';
				$this->appendTree( $lines, $this->tree( $orphaned ) );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The same overview as JSON (source IDs, flat per menu) for any
	 * future tooling.
	 *
	 * @param array<int, array<string, mixed>> $overview
	 */
	public function toJson( array $overview ): string {
		return json_encode( $overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	/**
	 * Write both formats into `$directory` (created if needed).
	 *
	 * @param array<int, array<string, mixed>> $overview
	 *
	 * @return string[] The basenames written, in a fixed order.
	 */
	public function writeAll( array $overview, string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0775, true );
		}

		$files = array(
			'menus-overview.md' => $this->toMarkdown( $overview ),
			'menus-overview.json' => $this->toJson( $overview ),
		);

		foreach ( $files as $name => $contents ) {
			file_put_contents( rtrim( $directory, '/' ) . '/' . $name, $contents );
		}

		return array_keys( $files );
	}

	/**
	 * Append one tree (already nested by tree()) as indented Markdown
	 * bullets.
	 *
	 * @param string[]                         $lines
	 * @param array<int, array<string, mixed>> $nodes
	 */
	private function appendTree( array &$lines, array $nodes, int $depth = 0 ): void {
		foreach ( $nodes as $node ) {
			$item = $node['item'];
			$label = (string) $item['label'] !== '' ? (string) $item['label'] : '(untitled)';
			$lines[] = str_repeat( '  ', $depth ) . sprintf( '- %s (%s → %s)', $label, (string) $item['type'] !== '' ? (string) $item['type'] : 'unknown', (string) $item['target'] !== '' ? (string) $item['target'] : '—' );
			$this->appendTree( $lines, $node['children'], $depth + 1 );
		}
	}
}
