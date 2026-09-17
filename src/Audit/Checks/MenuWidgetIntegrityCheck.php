<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Flags nav menu items whose linked object (`_menu_item_object_id`) no
 * longer exists, and widget-area entries in `sidebars_widgets` that
 * reference a widget instance missing from its `widget_{type}` option
 * (PLAN.md §9).
 *
 * @package MergeMultisite
 */
final class MenuWidgetIntegrityCheck implements AuditCheckInterface {

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
		$postMetaTable = $source->siteTable( 'postmeta', $blogId );
		$termsTable = $source->siteTable( 'terms', $blogId );

		// _menu_item_object_id points at different tables depending on
		// _menu_item_type: 'post_type' items store a posts.ID, while
		// 'taxonomy' items store a terms.term_id -- checking taxonomy
		// items against wp_posts would flag every category/tag menu
		// link as broken. 'custom' and 'post_type_archive' items have
		// no object row to check, so they're skipped.
		$items = $source->fetchAll(
			"SELECT item.ID,
			        typeMeta.meta_value AS item_type,
			        objectMeta.meta_value AS object_id
             FROM {$postsTable} item
             INNER JOIN {$postMetaTable} objectMeta
                ON objectMeta.post_id = item.ID AND objectMeta.meta_key = '_menu_item_object_id'
             LEFT JOIN {$postMetaTable} typeMeta
                ON typeMeta.post_id = item.ID AND typeMeta.meta_key = '_menu_item_type'
             WHERE item.post_type = 'nav_menu_item' AND objectMeta.meta_value != '0'"
		);

		$findings = array();
		foreach ( $items as $row ) {
			$itemType = (string) ( $row['item_type'] ?? '' );
			$objectId = (string) $row['object_id'];

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
					(int) $row['ID'],
					$itemType !== '' ? $itemType : 'post_type',
					$objectId
				),
				array(
				'blog_id' => $blogId,
				'menu_item_id' => (int) $row['ID'],
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
		$optionsTable = $source->siteTable( 'options', $blogId );

		$sidebarsValue = $source->fetchScalar(
			"SELECT option_value FROM {$optionsTable} WHERE option_name = 'sidebars_widgets' LIMIT 1"
		);

		if ( ! is_string( $sidebarsValue ) ) {
			return array();
		}

		$sidebars = @unserialize( $sidebarsValue );
		if ( ! is_array( $sidebars ) ) {
			return array();
		}

		$findings = array();
		foreach ( $sidebars as $sidebarId => $widgetIds ) {
			if ( ! is_array( $widgetIds ) || $sidebarId === 'wp_inactive_widgets' || $sidebarId === 'array_version' ) {
				continue;
			}

			foreach ( $widgetIds as $widgetId ) {
				if ( ! is_string( $widgetId ) || ! preg_match( '/^(?<type>[a-z0-9_-]+)-(?<index>\d+)$/', $widgetId, $m ) ) {
					continue;
				}

				$widgetOptionName = 'widget_' . $m['type'];
				$widgetOptionValue = $source->fetchScalar(
					"SELECT option_value FROM {$optionsTable} WHERE option_name = :name LIMIT 1",
					array( 'name' => $widgetOptionName )
				);

				if ( ! is_string( $widgetOptionValue ) ) {
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

				$instances = @unserialize( $widgetOptionValue );
				if ( is_array( $instances ) && ! array_key_exists( (int) $m['index'], $instances ) ) {
					$findings[] = AuditFinding::warning(
						$this->name(),
						sprintf(
							'Site %d, sidebar "%s" references widget "%s", but instance %d is missing from "%s".',
							$blogId,
							(string) $sidebarId,
							$widgetId,
							(int) $m['index'],
							$widgetOptionName
						),
						array(
						'blog_id' => $blogId,
						'sidebar' => $sidebarId,
						'widget_id' => $widgetId,
						)
					);
				}
			}
		}

		return $findings;
	}
}
