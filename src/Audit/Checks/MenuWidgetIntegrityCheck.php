<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\MenuInventory;
use MergeMultisite\Migration\WidgetInventory;

/**
 * Flags nav menu items whose linked object (`_menu_item_object_id`) no
 * longer exists, and widget-area entries in `sidebars_widgets` that
 * reference a widget instance missing from its `widget_{type}` option
 * (PLAN.md §9).
 *
 * @package MergeMultisite
 */
final class MenuWidgetIntegrityCheck implements AuditCheckInterface {

	private readonly MenuInventory $menuInventory;
	private readonly WidgetInventory $widgetInventory;

	public function __construct( ?MenuInventory $menuInventory = null, ?WidgetInventory $widgetInventory = null ) {
		$this->menuInventory = $menuInventory ?? new MenuInventory();
		$this->widgetInventory = $widgetInventory ?? new WidgetInventory();
	}

	public function name(): string {
		return 'menu-widget-integrity';
	}

	public function description(): string {
		return 'Nav menu items linking to objects that no longer exist, and sidebar widgets referencing missing widget options/instances.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		foreach ( $sites as $site ) {
			$findings = array( ...$findings, ...$this->checkMenuItems( $source, $site->blogId ) );
			$findings = array( ...$findings, ...$this->checkWidgets( $source, $site->blogId ) );
		}

		return $findings;
	}

	/**
	 * @return AuditFinding[]
	 */
	private function checkMenuItems( Connection $source, int $blogId ): array {
		$postsTable = $source->siteTable( 'posts', $blogId );
		$termsTable = $source->siteTable( 'terms', $blogId );

		// _menu_item_object_id points at different tables depending on
		// _menu_item_type: 'post_type' items store a posts.ID, while
		// 'taxonomy' items store a terms.term_id -- checking taxonomy
		// items against wp_posts would flag every category/tag menu
		// link as broken. 'custom' and 'post_type_archive' items have
		// no object row to check, so they're skipped.
		$items = $this->menuInventory->collect( $source, $blogId )['items'];

		$findings = array();
		foreach ( $items as $row ) {
			$itemType = (string) ( $row['item_type'] ?? '' );
			$objectId = (string) ( $row['object_id'] ?? '' );

			if ( $objectId === '' || $objectId === '0' ) {
				continue;
			}

			if ( $itemType === 'post_type' || $itemType === '' ) {
				// Pre-3.0 menu items and odd rows may lack the type
				// meta; default to the posts table as before.
				$exists = $source->fetchScalar(
					"SELECT ID FROM {$postsTable} WHERE ID = :id LIMIT 1",
					array( 'id' => $objectId )
				) !== null;
			} elseif ( $itemType === 'taxonomy' ) {
				$exists = $source->fetchScalar(
					"SELECT term_id FROM {$termsTable} WHERE term_id = :id LIMIT 1",
					array( 'id' => $objectId )
				) !== null;
			} else {
				continue; // custom, post_type_archive, anything else: no object to check.
			}

			if ( $exists ) {
				continue;
			}

			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'Site %d, Menu item %d (%s) links to missing object ID %s.',
					$blogId,
					(int) $row['post_id'],
					$itemType !== '' ? $itemType : 'post_type',
					$objectId
				),
				array(
				'blog_id' => $blogId,
				'menu_item_id' => (int) $row['post_id'],
				'item_type' => $itemType !== '' ? $itemType : 'post_type',
				'object_id' => $objectId,
				)
			);
		}

		return $findings;
	}

	/**
	 * @return AuditFinding[]
	 */
	private function checkWidgets( Connection $source, int $blogId ): array {
		$inventory = $this->widgetInventory->collect( $source, $blogId );

		if ( $inventory['sidebars_corrupt'] ) {
			return array(
				AuditFinding::warning(
					$this->name(),
					sprintf( 'Site %d has corrupt or malformed "sidebars_widgets" option data -- widget areas cannot be read.', $blogId ),
					array( 'blog_id' => $blogId )
				),
			);
		}

		if ( $inventory['sidebars'] === null ) {
			return array();
		}

		$findings = array();
		foreach ( $inventory['sidebars'] as $sidebarId => $widgetIds ) {
			if ( ! is_array( $widgetIds ) || $sidebarId === 'wp_inactive_widgets' || $sidebarId === 'array_version' ) {
				continue;
			}

			foreach ( $widgetIds as $widgetId ) {
				$parsedId = is_string( $widgetId ) ? WidgetInventory::parseWidgetId( $widgetId ) : null;
				if ( $parsedId === null ) {
					continue;
				}

				$widgetOptionName = 'widget_' . $parsedId['type'];
				$widgetOption = $inventory['widget_options'][ $widgetOptionName ] ?? null;

				if ( $widgetOption === null ) {
					$findings[] = AuditFinding::warning(
						$this->name(),
						sprintf(
							'Site %d, sidebar "%s" references widget "%s", but option "%s" does not exist.',
							$blogId,
							(string) $sidebarId,
							$widgetId,
							$widgetOptionName
						),
						array(
						'blog_id' => $blogId,
						'sidebar' => $sidebarId,
						'widget_id' => $widgetId,
						)
					);
					continue;
				}

				if ( $widgetOption['status'] === 'corrupt' ) {
					$findings[] = AuditFinding::warning(
						$this->name(),
						sprintf(
							'Site %d, sidebar "%s" references widget "%s", but option "%s" contains corrupt or malformed serialized data -- rebuild the widget.',
							$blogId,
							(string) $sidebarId,
							$widgetId,
							$widgetOptionName
						),
						array(
							'blog_id'   => $blogId,
							'sidebar'   => $sidebarId,
							'widget_id' => $widgetId,
						)
					);
					continue;
				}

				if ( ! array_key_exists( $parsedId['index'], $widgetOption['instances'] ) ) {
					$findings[] = AuditFinding::warning(
						$this->name(),
						sprintf(
							'Site %d, sidebar "%s" references widget "%s", but instance %d is missing from "%s".',
							$blogId,
							(string) $sidebarId,
							$widgetId,
							$parsedId['index'],
							$widgetOptionName
						),
						array(
							'blog_id'   => $blogId,
							'sidebar'   => $sidebarId,
							'widget_id' => $widgetId,
						)
					);
				}
			}
		}

		return $findings;
	}
}
