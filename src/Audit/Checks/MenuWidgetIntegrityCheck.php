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

		$rows = $source->fetchAll(
			"SELECT item.ID, meta.meta_value AS object_id
             FROM {$postsTable} item
             INNER JOIN {$postMetaTable} meta ON meta.post_id = item.ID AND meta.meta_key = '_menu_item_object_id'
             LEFT JOIN {$postsTable} target ON target.ID = CAST(meta.meta_value AS UNSIGNED)
             WHERE item.post_type = 'nav_menu_item' AND target.ID IS NULL AND meta.meta_value != '0'"
		);

		$findings = array();
		foreach ( $rows as $row ) {
			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'Site %d nav menu item %d links to missing object ID %s.',
					$blogId,
					(int) $row['ID'],
					(string) $row['object_id']
				),
				array(
				'blog_id' => $blogId,
				'menu_item_id' => (int) $row['ID'],
				'object_id' => $row['object_id'],
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
							'Site %d sidebar "%s" references widget "%s", but option "%s" does not exist.',
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
							'Site %d sidebar "%s" references widget "%s", but instance %d is missing from "%s".',
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
