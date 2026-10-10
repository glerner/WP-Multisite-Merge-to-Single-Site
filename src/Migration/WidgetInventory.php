<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Db\Connection;

/**
 * Per-site inventory of WordPress's classic widget storage
 * (PLAN.md §7.5).
 *
 * Widgets live in two places in `wp_options`:
 *
 *  - `sidebars_widgets` -- serialized map of sidebar ID => ordered list
 *    of widget IDs (`"{type}-{index}"`, e.g. `text-3` = instance 3 of
 *    the `text` widget type). `wp_inactive_widgets` is a pseudo-sidebar
 *    holding unassigned widgets; `array_version` is a format marker.
 *  - `widget_{type}` -- serialized map of instance index => settings
 *    array for every widget of that type.
 *
 * Shared by MenuWidgetIntegrityCheck (references-vs-instances auditing)
 * and MenuMigrator (which migrates `sidebars_widgets` into
 * orphaned/inactive areas on the destination and copies each
 * `widget_{type}` option through SerializedDataRewriter).
 *
 * @package MergeMultisite
 */
final class WidgetInventory {

	/**
	 * Parse a sidebar widget ID into its type + instance index:
	 * `text-3` => `['type' => 'text', 'index' => 3]`. Returns null for
	 * IDs that don't follow the multi-widget pattern.
	 *
	 * @return array{type:string, index:int}|null
	 */
	public static function parseWidgetId( string $widgetId ): ?array {
		if ( preg_match( '/^(?<type>[a-z0-9_-]+)-(?<index>\d+)$/', $widgetId, $matches ) !== 1 ) {
			return null;
		}

		return array(
			'type' => $matches['type'],
			'index' => (int) $matches['index'],
		);
	}

	/**
	 * Load one site's `sidebars_widgets` option plus every `widget_%`
	 * option row, unserializing each once.
	 *
	 * @return array{
	 *     sidebars: array<string, mixed>|null,
	 *     sidebars_corrupt: bool,
	 *     widget_options: array<string, array{status:string, instances:array}>
	 * }
	 * `sidebars` is null when the option row doesn't exist at all;
	 * `sidebars_corrupt` marks a row that exists but won't unserialize.
	 * `widget_options` is keyed by option name (`widget_text`, ...) with
	 * status 'ok' (parsed) or 'corrupt' (unparseable) — options NOT
	 * present in the map simply don't exist in the site's options table.
	 */
	public function collect( Connection $source, int $blogId ): array {
		$optionsTable = $source->siteTable( 'options', $blogId );

		$sidebarsValue = $source->fetchScalar(
			"SELECT option_value FROM {$optionsTable} WHERE option_name = 'sidebars_widgets' LIMIT 1"
		);

		$sidebars = null;
		$sidebarsCorrupt = false;
		if ( is_string( $sidebarsValue ) ) {
			// Security: 'allowed_classes' => false prevents PHP Object Injection.
			$parsed = @unserialize( $sidebarsValue, array( 'allowed_classes' => false ) );
			if ( is_array( $parsed ) ) {
				$sidebars = $parsed;
			} else {
				$sidebarsCorrupt = true;
			}
		}

		$widgetRows = $source->fetchAll(
			"SELECT option_name, option_value FROM {$optionsTable}
             WHERE option_name LIKE 'widget\$_%' ESCAPE '$'
             ORDER BY option_name"
		);

		$widgetOptions = array();
		foreach ( $widgetRows as $row ) {
			$value = $row['option_value'];

			// Security: 'allowed_classes' => false prevents PHP Object Injection.
			$instances = is_string( $value ) ? @unserialize( $value, array( 'allowed_classes' => false ) ) : false;

			$widgetOptions[ (string) $row['option_name'] ] = array(
				'status' => is_array( $instances ) ? 'ok' : 'corrupt',
				'instances' => is_array( $instances ) ? $instances : array(),
			);
		}

		return array(
			'sidebars' => $sidebars,
			'sidebars_corrupt' => $sidebarsCorrupt,
			'widget_options' => $widgetOptions,
		);
	}
}
