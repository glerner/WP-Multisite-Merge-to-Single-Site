<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Widgets phase of the migration (PLAN.md §7.5).
 *
 * Copies every included site's classic widget storage into the
 * destination as INACTIVE/ORPHANED data, namespaced per site.
 * The classic widget storage is each `widget_{type}` option and
 * `sidebars_widgets`.
 * The merged destination theme's own widget areas are never silently
 * overwritten.
 * Every migrated widget stays browsable in wp-admin.
 * Widgets in unregistered areas appear under "Inactive Widgets".
 *
 * What this phase does per site:
 *  - Copies each source `widget_{type}` option.
 *    Instance indexes are RENUMBERED beyond the destination's current
 *    maximum for that type.
 *    Widgets from N sites never collide on one destination option.
 *    Instance settings run through a recursive 'nav_menu' remap.
 *    The classic "Navigation Menu" widget stores the nav_menu TERM ID.
 *    MenuMigrator recreated that term under a new ID.
 *    Unmapped references are kept and counted as targets_unmapped.
 *    The `_multiwidget` marker is preserved.
 *  - Keeps the destination's own `sidebars_widgets` areas untouched.
 *    Gains one namespaced area per source sidebar:
 *    `site{blogId}_{sidebarId}`.
 *    The source's `wp_inactive_widgets` pseudo-area becomes
 *    `site{blogId}_wp_inactive_widgets`.
 *    Widget IDs are rewritten to the renumbered instances.
 *    Widgets referenced by a sidebar but missing from their option
 *    are skipped and counted as instances_missing, never silent.
 *  - A corrupt `sidebars_widgets` option (won't unserialize) skips the
 *    site's widget data.
 *    It is reported (corrupt_sites), matching the integrity checker's
 *    stance.
 *
 * Widget AREA placement is a manual decision after the merge.
 * The report flags everything this phase moved for review.
 * Idempotent via 'widget' (per widget_ option) and 'sidebars_widgets'
 * entries in IdMap/MigrationTable.
 *
 * @phpstan-type WidgetMigrationReport array{
 *     widget_options_written: int,
 *     instances_migrated: int,
 *     instances_skipped: int,
 *     instances_missing: int,
 *     sidebar_areas_written: int,
 *     targets_unmapped: int,
 *     corrupt_sites: array<int, string>,
 *     warnings: array<int, string>,
 *     by_site: array<int, array{widgets: int, instances: int}>
 * }
 *
 * @package MergeMultisite
 */
final class WidgetMigrator {

	public function __construct(
		private readonly ?WidgetInventory $inventory = null,
	) {
	}

	/**
	 * Runs the widget migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return WidgetMigrationReport
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
		$inventory = $this->inventory ?? new WidgetInventory();

		$destOptionsTable = $destination->siteTable( 'options', 1 );

		$report = array(
			'widget_options_written' => 0,
			'instances_migrated' => 0,
			'instances_skipped' => 0,
			'instances_missing' => 0,
			'sidebar_areas_written' => 0,
			'targets_unmapped' => 0,
			'corrupt_sites' => array(),
			'warnings' => array(),
			'by_site' => array(),
		);
		$warnings = array(); // collected separately so warn() never widens $report's shape

		// Destination widget instances, keyed by option name, loaded
		// lazily and written back after each site; keeping them in
		// memory across sites gives stable cross-site renumbering.
		$destInstances = array();

		foreach ( $sites as $site ) {
			$blogId = $site->blogId;
			$optionsTable = $source->siteTable( 'options', $blogId );

			$collected = $inventory->collect( $source, $blogId );

			if ( $collected['sidebars_corrupt'] ) {
				$message = sprintf( 'Site %d: corrupt "sidebars_widgets" option data — widget areas and instances were not migrated.', $blogId );
				$report['corrupt_sites'][ $blogId ] = $message;
				$this->warn( $warnings, $logger, $message );
				continue;
			}

			if ( $collected['widget_options'] === array() && $collected['sidebars'] === null ) {
				continue;
			}

			if ( ! $dryRun ) {
				$destination->beginTransaction();
			}

			try {
				$instanceMap = array(); // "type-oldIndex" => newIndex (for sidebars_widgets rewriting).

				foreach ( $collected['widget_options'] as $optionName => $widgetOption ) {
					$type = substr( $optionName, strlen( 'widget_' ) );

					if ( $idMap->has( 'widget', $blogId, $optionName ) ) {
						++$report['instances_skipped'];
						continue;
					}

					if ( $widgetOption['status'] === 'corrupt' ) {
						$this->warn( $warnings, $logger, sprintf( 'Site %d: option "%s" contains corrupt serialized data; its widgets were not migrated.', $blogId, $optionName ) );
						continue;
					}

					$dest = $this->loadDestInstances( $destination, $destOptionsTable, $optionName, $destInstances );

					$newInstances = array();
					foreach ( $widgetOption['instances'] as $oldIndex => $settings ) {
						if ( $oldIndex === '_multiwidget' ) {
							$newInstances['_multiwidget'] = $settings;
							continue;
						}

						$nextIndex = $this->nextInstanceIndex( $dest );
						$remapped = $this->remapInstanceSettings( $settings, $blogId, $idMap, $report );
						$dest[ $nextIndex ] = $remapped;
						$newInstances[ $nextIndex ] = $remapped;
						$instanceMap[ $type . '-' . $oldIndex ] = $nextIndex;
						++$report['instances_migrated'];
					}

					if ( $dryRun ) {
						$report['by_site'][ $blogId ]['instances'] = ( $report['by_site'][ $blogId ]['instances'] ?? 0 ) + count( $newInstances );
						continue;
					}

					$optionId = $this->upsertOption( $destination, $destOptionsTable, $optionName, serialize( $dest ), 'yes' );
					// Refresh the cache so the NEXT site renumbers past
					// these instances (PHP arrays are copy-on-write).
					$destInstances[ $optionName ] = $dest;
					$idMap->set( 'widget', $blogId, $optionName, $optionId );
					$mapTable->record( $destination, 'widget', $blogId, $optionName, $optionId );

					++$report['widget_options_written'];
					$report['by_site'][ $blogId ]['widgets'] = ( $report['by_site'][ $blogId ]['widgets'] ?? 0 ) + 1;
					$report['by_site'][ $blogId ]['instances'] = ( $report['by_site'][ $blogId ]['instances'] ?? 0 ) + count( $newInstances );
				}

				if ( $collected['sidebars'] !== null ) {
					$this->migrateSidebars(
						$destination,
						$destOptionsTable,
						$collected['sidebars'],
						$blogId,
						$instanceMap,
						$idMap,
						$mapTable,
						$dryRun,
						$report
					);
				}

				if ( ! $dryRun ) {
					$destination->commit();
				}
			} catch ( \Throwable $e ) {
				if ( $destination->inTransaction() ) {
					$destination->rollBack();
				}
				$logger->error( sprintf( 'Widget migration failed, rolled back: %s', $e->getMessage() ) );
				throw $e;
			}
		}

		$report['warnings'] = $warnings;

		$logger->info(
			sprintf(
				'Widget migration%s: %d widget option(s) written, %d instance(s) migrated, %d sidebar area(s) written, %d unmapped nav_menu reference(s), %d missing instance reference(s).',
				$dryRun ? ' (dry run)' : '',
				$report['widget_options_written'],
				$report['instances_migrated'],
				$report['sidebar_areas_written'],
				$report['targets_unmapped'],
				$report['instances_missing']
			)
		);

		return $report;
	}

	/**
	 * Copy the source site's sidebars_widgets into namespaced
	 * destination areas.
	 * The destination's own areas are left untouched.
	 * That includes its wp_inactive_widgets.
	 * Each source sidebar becomes `site{blogId}_{sidebarId}`.
	 *
	 * @param array<string, mixed>  $sourceSidebars
	 * @param array<string, int>    $instanceMap  "type-oldIndex" => newIndex.
	 * @param WidgetMigrationReport $report
	 */
	private function migrateSidebars(
		Connection $destination,
		string $optionsTable,
		array $sourceSidebars,
		int $blogId,
		array $instanceMap,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		array &$report
	): void {
		$optionName = 'sidebars_widgets';

		if ( $idMap->has( 'sidebars_widgets', $blogId, $optionName ) ) {
			++$report['instances_skipped'];
			return;
		}

		$destSidebars = $this->loadDestSidebars( $destination, $optionsTable );

		foreach ( $sourceSidebars as $sidebarId => $widgetIds ) {
			if ( $sidebarId === 'array_version' || ! is_array( $widgetIds ) ) {
				continue;
			}

			$newIds = array();
			foreach ( $widgetIds as $widgetId ) {
				if ( ! is_string( $widgetId ) ) {
					continue;
				}

				$parsed = WidgetInventory::parseWidgetId( $widgetId );
				if ( $parsed === null ) {
					continue;
				}

				$newIndex = $instanceMap[ $parsed['type'] . '-' . $parsed['index'] ] ?? null;
				if ( $newIndex === null ) {
					++$report['instances_missing'];
					continue;
				}

				$newIds[] = $parsed['type'] . '-' . $newIndex;
			}

			if ( $newIds === array() ) {
				continue;
			}

			$destSidebars[ 'site' . $blogId . '_' . (string) $sidebarId ] = $newIds;
			++$report['sidebar_areas_written'];
		}

		if ( $dryRun ) {
			return;
		}

		$optionId = $this->upsertOption( $destination, $optionsTable, $optionName, serialize( $destSidebars ), 'yes' );
		$idMap->set( 'sidebars_widgets', $blogId, $optionName, $optionId );
		$mapTable->record( $destination, 'sidebars_widgets', $blogId, $optionName, $optionId );
	}

	/**
	 * Load (and cache) the destination's current instances for one
	 * widget_{type} option, so renumbering continues past earlier
	 * sites and the destination's own widgets are never clobbered.
	 *
	 * @param array<string, array<int|string, mixed>> $destInstances by ref, keyed by option name.
	 *
	 * @return array<int|string, mixed>
	 */
	private function loadDestInstances( Connection $destination, string $optionsTable, string $optionName, array &$destInstances ): array {
		if ( isset( $destInstances[ $optionName ] ) ) {
			return $destInstances[ $optionName ];
		}

		$raw = $destination->fetchScalar(
			"SELECT option_value FROM {$optionsTable} WHERE option_name = :name LIMIT 1",
			array( 'name' => $optionName )
		);
		$instances = array();
		if ( is_string( $raw ) ) {
			// Security: 'allowed_classes' => false prevents PHP Object Injection.
			$parsed = @unserialize( $raw, array( 'allowed_classes' => false ) );
			if ( is_array( $parsed ) ) {
				$instances = $parsed;
			}
		}

		$destInstances[ $optionName ] = $instances;

		return $destInstances[ $optionName ];
	}

	/**
	 * The next free instance index for a widget type.
	 * It is one past the current maximum numeric key.
	 * WP indexes widget instances 1, 2, 3, ...
	 * `_multiwidget` is the only non-numeric key.
	 *
	 * @param array<int|string, mixed> $instances
	 */
	private function nextInstanceIndex( array $instances ): int {
		$max = 0;
		foreach ( array_keys( $instances ) as $key ) {
			if ( is_int( $key ) ) {
				$max = max( $max, $key );
			} elseif ( ctype_digit( (string) $key ) ) {
				$max = max( $max, (int) $key );
			}
		}

		return $max + 1;
	}

	/**
	 * Recursively remap `nav_menu` keys inside widget instance
	 * settings (the classic Navigation Menu widget stores the
	 * nav_menu TERM id, recreated by MenuMigrator under a new id).
	 * Any other value passes through untouched.
	 *
	 * @param WidgetMigrationReport $report
	 */
	private function remapInstanceSettings( mixed $value, int $blogId, IdMap $idMap, array &$report ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( $value as $key => $nested ) {
			if ( $key === 'nav_menu' && ( is_int( $nested ) || ( is_string( $nested ) && is_numeric( $nested ) ) ) ) {
				$newTermId = $idMap->get( 'nav_menu', $blogId, (int) $nested );
				if ( $newTermId === null ) {
					++$report['targets_unmapped'];
				} else {
					$nested = $newTermId;
				}
			}
			$result[ $key ] = $this->remapInstanceSettings( $nested, $blogId, $idMap, $report );
		}

		return $result;
	}

	/**
	 * The destination's current sidebars_widgets value (a fresh
	 * install has one with an empty wp_inactive_widgets), ready for
	 * namespaced areas to be appended.
	 *
	 * @return array<string, mixed>
	 */
	private function loadDestSidebars( Connection $destination, string $optionsTable ): array {
		$raw = $destination->fetchScalar(
			"SELECT option_value FROM {$optionsTable} WHERE option_name = 'sidebars_widgets' LIMIT 1"
		);
		if ( is_string( $raw ) ) {
			// Security: 'allowed_classes' => false prevents PHP Object Injection.
			$parsed = @unserialize( $raw, array( 'allowed_classes' => false ) );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}

		return array(
			'array_version' => 3,
			'wp_inactive_widgets' => array(),
		);
	}

	/**
	 * Insert an option on the destination, or update the existing row
	 * (wp_options.option_name is unique). Returns the destination
	 * option_id.
	 */
	private function upsertOption( Connection $destination, string $optionsTable, string $name, string $value, string $autoload ): int {
		$existing = $destination->fetchOne(
			"SELECT option_id FROM {$optionsTable} WHERE option_name = :name",
			array( 'name' => $name )
		);

		if ( $existing !== null ) {
			$destination->execute(
				"UPDATE {$optionsTable} SET option_value = :value, autoload = :autoload WHERE option_id = :id",
				array(
					'value' => $value,
					'autoload' => $autoload,
					'id' => (int) $existing['option_id'],
				)
			);

			return (int) $existing['option_id'];
		}

		$destination->execute(
			"INSERT INTO {$optionsTable} (option_name, option_value, autoload) VALUES (:name, :value, :autoload)",
			array(
				'name' => $name,
				'value' => $value,
				'autoload' => $autoload,
			)
		);

		return $destination->lastInsertId();
	}

	/**
	 * Log a warning and record it in the report.
	 *
	 * @param string[] $warnings
	 */
	private function warn( array &$warnings, Logger $logger, string $message ): void {
		$warnings[] = $message;
		$logger->warning( $message );
	}
}
