<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Config\PluginOptionRule;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Generic options phase of the migration (PLAN.md §7.5).
 *
 * Every `wp_options` row on every included site migrates into the
 * destination single site, unless an exclusion applies.
 * This is the "migrate-by-default" stance.
 * It keeps merged sites from silently breaking on dropped plugin
 * settings.
 *
 * Built-in never-migrate list (§7.5):
 *  - Destination identity: siteurl, home, blogname, blogdescription,
 *    admin_email, admin_email_lifespan.
 *  - Install state: db_version, fresh_site.
 *  - Runtime/transients: cron, rewrite_rules, _transient_*,
 *    _site_transient_*, Action Scheduler lock/demarkation rows.
 *  - Per-site filesystem paths: upload_path, fileupload_url,
 *    upload_url_path.
 *  - Options owned by other phases or by your destination decision:
 *    sidebars_widgets/widget_* (widgets), active_plugins (the union
 *    is reported; the destination's own list stays),
 *    template/stylesheet/theme_mods_* (theme decision),
 *    show_on_front/page_on_front/page_for_posts (destination
 *    front-page decision), {prefix}user_roles (Phase 3).
 * Every exclusion is counted and listed (excluded_lines).
 * Nothing is silently dropped.
 *
 * config/option-keys.php rules (PluginOptionRule):
 *  - 'exclude' and 'needs_adapter' skip options (reported).
 *  - 'include_partial' strips configured sub-keys from the
 *    serialized value.
 *  - 'exclude_option_keys' drops individual options under an
 *    included prefix.
 *  - Everything else migrates by default.
 *
 * Conflict resolution (§7.5):
 *  - The same option_name on several sites with identical values
 *    migrates once.
 *  - Differing values resolve to main_site when configured and
 *    present, otherwise the lowest blog_id.
 *  - Every divergence is recorded for the report.
 *  - The integrity checker's divergent-options data holds the
 *    value/site map the report points at.
 *
 * Known core option IDs are remapped:
 *  - site_icon uses the attachment IdMap.
 *  - default_category uses the terms IdMap.
 *  - Unmapped values are kept and counted (option_ids_unmapped).
 *  - They are never silently dropped.
 *
 * Credential-named options and serialized sub-keys
 * (PluginOptionRule::isSensitiveKeyName) DO migrate.
 * The destination plugin needs them.
 * They are listed in the report so no secret travels invisibly.
 *
 * Values copy verbatim otherwise.
 * URL/domain rewriting is Phase 8.
 * Idempotent via 'option' entries (site ID 0, keyed by option name)
 * in IdMap/MigrationTable.
 *
 * @phpstan-type OptionsMigrationReport array{
 *     options_migrated: int,
 *     options_skipped: int,
 *     options_excluded: int,
 *     option_ids_unmapped: int,
 *     destination_overwrites: int,
 *     conflicts_resolved: int,
 *     conflicts_identical: int,
 *     excluded_lines: array<int, string>,
 *     needs_adapter: array<int, string>,
 *     sensitive_migrated: array<int, string>,
 *     sensitive_subkeys: array<int, string>,
 *     active_plugins_union: array<int, string>,
 *     divergences: array<int, array{option: string, winner_blog_id: int, sites: array<int, int>}>,
 *     front_page: array{blog_id: int, show_on_front: string, page_on_front: int, page_on_front_destination: int|null, page_for_posts: int, page_for_posts_destination: int|null}|null,
 *     warnings: array<int, string>,
 *     by_site: array<int, array{options: int}>
 * }
 *
 * @package MergeMultisite
 */
final class OptionsMigrator {

	/**
	 * Option names that never migrate on any site (PLAN.md §7.5
	 * built-in never-migrate list): destination-install state,
	 * runtime scratch, and options owned by other phases or your
	 * destination decisions.
	 *
	 * @var string[]
	 */
	private const NEVER_MIGRATE_OPTION_NAMES = array(
		// Destination identity.
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'admin_email_lifespan',
		// Install state.
		'db_version',
		'fresh_site',
		// Runtime / transients.
		'cron',
		'rewrite_rules',
		// Per-site filesystem paths.
		'upload_path',
		'fileupload_url',
		'upload_url_path',
		// Owned by other phases / destination decisions.
		'sidebars_widgets',
		'active_plugins',
		'template',
		'stylesheet',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
	);

	/**
	 * Prefix patterns that never migrate.
	 * The widgets phase owns `widget_*`.
	 * The theme decision owns `theme_mods_*`.
	 *
	 * @var string[]
	 */
	private const NEVER_MIGRATE_OPTION_PATTERNS = array(
		'widget_*',
		'theme_mods_*',
	);

	/**
	 * Known core options whose value is a source ID that must be
	 * remapped through an IdMap: option name => IdMap entity type.
	 * Values that don't resolve are kept verbatim and counted.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_OPTION_ID_MAPS = array(
		'site_icon' => 'attachment',
		'default_category' => 'term',
	);

	public function __construct() {
	}

	/**
	 * Runs the options migration phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return OptionsMigrationReport
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
		$destOptionsTable = $destination->siteTable( 'options', 1 );

		$report = array(
			'options_migrated' => 0,
			'options_skipped' => 0,
			'options_excluded' => 0,
			'option_ids_unmapped' => 0,
			'destination_overwrites' => 0,
			'conflicts_resolved' => 0,
			'conflicts_identical' => 0,
			'excluded_lines' => array(),
			'needs_adapter' => array(),
			'sensitive_migrated' => array(),
			'sensitive_subkeys' => array(),
			'active_plugins_union' => array(),
			'divergences' => array(),
			'front_page' => null,
			'warnings' => array(),
			'by_site' => array(),
		);
		$warnings = array(); // collected separately so warn() never widens $report's shape

		// Pass A: gather each site's eligible options in memory, plus
		// the exclusion/sensitivity review trail.
		$byName = array(); // option_name => siteId => array{value: string, autoload: string}
		$activePlugins = array();
		$frontPageCandidates = array(); // option_name => siteId => value (excluded, but captured for the report).
		$userRolesName = $source->config->tablePrefix . 'user_roles';

		foreach ( $sites as $site ) {
			$blogId = $site->blogId;
			$optionsTable = $source->siteTable( 'options', $blogId );

			foreach (
				$source->fetchAll( "SELECT option_name, option_value, autoload FROM {$optionsTable} ORDER BY option_name" ) as $row
			) {
				$name = (string) $row['option_name'];

				if ( $name === 'active_plugins' ) {
					$activePlugins[ $blogId ] = $row['option_value'];
				}
				if ( $name === 'show_on_front' || $name === 'page_on_front' || $name === 'page_for_posts' ) {
					$frontPageCandidates[ $name ][ $blogId ] = (string) $row['option_value'];
				}

				$exclusion = $this->exclusionReason( $name, $userRolesName, $config );
				if ( $exclusion !== null ) {
					++$report['options_excluded'];
					$report['excluded_lines'][] = sprintf( '"%s" (site %d): %s', $name, $blogId, $exclusion );
					continue;
				}

				$rule = $this->governingRule( $name, $config );
				if ( $rule !== null && $rule->mode === PluginOptionRule::MODE_EXCLUDE ) {
					++$report['options_excluded'];
					$report['excluded_lines'][] = sprintf( '"%s" (site %d): excluded by option-keys.php rule "%s"%s', $name, $blogId, $rule->pluginSlug, $rule->reason !== null ? ' (' . $rule->reason . ')' : '' );
					continue;
				}
				if ( $rule !== null && $rule->mode === PluginOptionRule::MODE_NEEDS_ADAPTER ) {
					$report['needs_adapter'][] = sprintf( '"%s" (site %d): plugin "%s" needs a src/Plugins/ adapter%s', $name, $blogId, $rule->pluginSlug, $rule->reason !== null ? ' (' . $rule->reason . ')' : '' );
					continue;
				}
				if ( $rule !== null && ! $rule->shouldMigrateOption( $name ) ) {
					++$report['options_excluded'];
					$report['excluded_lines'][] = sprintf( '"%s" (site %d): dropped by option-keys.php exclude_option_keys under "%s"', $name, $blogId, $rule->pluginSlug );
					continue;
				}

				$byName[ $name ][ $blogId ] = array(
					'value' => (string) $row['option_value'],
					'autoload' => (string) $row['autoload'],
				);

				if ( PluginOptionRule::isSensitiveKeyName( $name ) ) {
					$report['sensitive_migrated'][] = sprintf( '"%s" (site %d)', $name, $blogId );
				}
				foreach ( $this->sensitiveSubkeys( (string) $row['option_value'] ) as $subkey ) {
					$report['sensitive_subkeys'][] = sprintf( '"%s" -> %s (site %d)', $name, $subkey, $blogId );
				}
			}
		}

		$report['active_plugins_union'] = $this->activePluginsUnion( $activePlugins );
		$report['front_page'] = $this->resolveFrontPage( $frontPageCandidates, $config, $idMap );

		// Pass B: resolve per-name conflicts and write, in batches.
		$names = array_keys( $byName );
		sort( $names );

		$destOptionIds = $this->destinationOptionIds( $destination, $destOptionsTable );

		foreach ( array_chunk( $names, max( 1, $config->batchSize ) ) as $batch ) {
			if ( ! $dryRun ) {
				$destination->beginTransaction();
			}

			try {
				foreach ( $batch as $name ) {
					if ( $idMap->has( 'option', 0, $name ) ) {
						++$report['options_skipped'];
						continue;
					}

					$winnerSite = $this->resolveWinner( $name, $byName[ $name ], $config, $report );
					$winner = $byName[ $name ][ $winnerSite ];

					$rule = $this->governingRule( $name, $config );
					$value = $winner['value'];
					if ( $rule !== null && $rule->mode === PluginOptionRule::MODE_INCLUDE_PARTIAL ) {
						$value = $this->stripSubkeys( $value, $rule->excludeSubkeys );
					}
					$value = $this->rewriteKnownOptionIds( $value, $name, $winnerSite, $idMap, $report );

					if ( $dryRun ) {
						++$report['options_migrated'];
						$report['by_site'][ $winnerSite ]['options'] = ( $report['by_site'][ $winnerSite ]['options'] ?? 0 ) + 1;
						continue;
					}

					$optionId = $this->upsertOption( $destination, $destOptionsTable, $name, $value, $winner['autoload'], $destOptionIds, $report );
					$idMap->set( 'option', 0, $name, $optionId );
					$mapTable->record( $destination, 'option', 0, $name, $optionId );

					++$report['options_migrated'];
					$report['by_site'][ $winnerSite ]['options'] = ( $report['by_site'][ $winnerSite ]['options'] ?? 0 ) + 1;
				}

				if ( ! $dryRun ) {
					$destination->commit();
				}
			} catch ( \Throwable $e ) {
				if ( $destination->inTransaction() ) {
					$destination->rollBack();
				}
				$logger->error( sprintf( 'Options migration failed, rolled back: %s', $e->getMessage() ) );
				throw $e;
			}
		}

		$report['warnings'] = $warnings;

		$logger->info(
			sprintf(
				'Options migration%s: %d option(s) migrated, %d skipped (already mapped), %d excluded, %d overwrote destination rows, %d conflict(s) resolved, %d identical merge(s), %d unmapped ID(s).',
				$dryRun ? ' (dry run)' : '',
				$report['options_migrated'],
				$report['options_skipped'],
				$report['options_excluded'],
				$report['destination_overwrites'],
				$report['conflicts_resolved'],
				$report['conflicts_identical'],
				$report['option_ids_unmapped']
			)
		);

		return $report;
	}

	/**
	 * Why a built-in option never migrates, or null when it's
	 * eligible. The `{prefix}user_roles` name is computed from the
	 * SOURCE prefix (Phase 3 owns roles).
	 */
	private function exclusionReason( string $name, string $userRolesName, MergeConfig $config ): ?string {
		if ( in_array( $name, self::NEVER_MIGRATE_OPTION_NAMES, true ) ) {
			if ( $name === 'active_plugins' ) {
				return 'the union of included sites\' lists is reported; the destination\'s own active_plugins stays.';
			}
			if ( $name === 'template' || $name === 'stylesheet' || str_starts_with( $name, 'theme_mods_' ) ) {
				return 'theme decision (PLAN.md §7.5): the destination\'s own theme/theme-mods stay; stale wp_template rows are handled by bin/retag-templates.sh.';
			}
			if ( $name === 'show_on_front' || $name === 'page_on_front' || $name === 'page_for_posts' ) {
				return 'destination front-page decision (PLAN.md §7.5).';
			}

			return 'built-in never-migrate list (destination-install state / runtime / other phase).';
		}
		if ( $name === $userRolesName ) {
			return 'roles are migrated by Phase 3 (UserMigrator).';
		}
		foreach ( self::NEVER_MIGRATE_OPTION_PATTERNS as $pattern ) {
			if ( $this->matchesPattern( $name, $pattern ) ) {
				return 'owned by the widgets phase / theme decision (PLAN.md §7.5).';
			}
		}
		if ( PluginOptionRule::isBuiltInExcludedOptionName( $name ) ) {
			return 'built-in runtime/transient exclusion (PluginOptionRule::BUILT_IN_EXCLUDED_OPTION_PATTERNS).';
		}

		return null;
	}

	/**
	 * The rule governing an option name, if any.
	 * A load-bearing match (exclude, include_partial, needs_adapter)
	 * wins over documentation-only include entries.
	 * Otherwise the first include match wins, for report attribution.
	 */
	private function governingRule( string $name, MergeConfig $config ): ?PluginOptionRule {
		$include = null;
		foreach ( $config->pluginOptionRules as $rule ) {
			if ( ! $rule->matchesOptionName( $name ) ) {
				continue;
			}
			if ( $rule->mode !== PluginOptionRule::MODE_INCLUDE ) {
				return $rule;
			}
			$include ??= $rule;
		}

		return $include;
	}

	/**
	 * Pick the site whose option value wins.
	 * A single site wins by default.
	 * Any site wins when all values are identical.
	 * Otherwise main_site wins when configured and present.
	 * Otherwise the lowest blog_id wins.
	 * Divergences are recorded for the report.
	 * The integrity checker's divergent-options data holds the
	 * value/site map.
	 *
	 * @param array<int, array{value: string, autoload: string}> $candidates
	 * @param OptionsMigrationReport                             $report
	 */
	private function resolveWinner( string $name, array $candidates, MergeConfig $config, array &$report ): int {
		$siteIds = array_keys( $candidates );

		if ( count( $siteIds ) === 1 ) {
			return $siteIds[0];
		}

		$firstValue = $this->normalizeValue( $candidates[ $siteIds[0] ]['value'] );
		$allIdentical = true;
		foreach ( $siteIds as $siteId ) {
			if ( $this->normalizeValue( $candidates[ $siteId ]['value'] ) !== $firstValue ) {
				$allIdentical = false;
				break;
			}
		}

		if ( $allIdentical ) {
			++$report['conflicts_identical'];

			return $siteIds[0];
		}

		++$report['conflicts_resolved'];
		$winner = $config->mainSite ?? null;
		if ( $winner === null || ! isset( $candidates[ $winner ] ) ) {
			$winner = min( $siteIds );
		}

		$report['divergences'][] = array(
			'option' => $name,
			'winner_blog_id' => $winner,
			'sites' => $siteIds,
		);

		return $winner;
	}

	/**
	 * Serialized arrays compare by their unserialized value.
	 * Key order matters, matching the integrity checker's divergence
	 * view.
	 * Everything else compares as the raw string.
	 */
	private function normalizeValue( string $value ): mixed {
		$trimmed = trim( $value );
		if ( $trimmed !== '' && $trimmed[0] === 'a' && str_contains( $trimmed, ':' ) ) {
			// Security: 'allowed_classes' => false prevents PHP Object Injection.
			$unserialized = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
			if ( is_array( $unserialized ) ) {
				return $unserialized;
			}
		}

		return $value;
	}

	/**
	 * Strip configured sub-keys from a serialized array value.
	 * Used for include_partial rules, e.g. wordfence's IP/log keys.
	 * Non-serialized values copy unchanged.
	 *
	 * @param string[] $excludeSubkeys
	 */
	private function stripSubkeys( string $value, array $excludeSubkeys ): string {
		$trimmed = trim( $value );
		if ( $trimmed === '' || $trimmed[0] !== 'a' || ! str_contains( $trimmed, ':' ) ) {
			return $value;
		}

		// Security: 'allowed_classes' => false prevents PHP Object Injection.
		$data = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
		if ( ! is_array( $data ) ) {
			return $value;
		}

		foreach ( $excludeSubkeys as $subkey ) {
			unset( $data[ $subkey ] );
		}

		return serialize( $data );
	}

	/**
	 * Remap known core option IDs (site_icon → attachment,
	 * default_category → term) through the winner site's IdMap.
	 * Unmapped values are kept verbatim and counted.
	 *
	 * @param OptionsMigrationReport $report
	 */
	private function rewriteKnownOptionIds( string $value, string $name, int $blogId, IdMap $idMap, array &$report ): string {
		$entityType = self::KNOWN_OPTION_ID_MAPS[ $name ] ?? null;
		if ( $entityType === null || $value === '' || ! is_numeric( $value ) ) {
			return $value;
		}

		$newId = $idMap->get( $entityType, $blogId, (int) $value );
		if ( $newId === null ) {
			++$report['option_ids_unmapped'];

			return $value;
		}

		return (string) $newId;
	}

	/**
	 * Recursively find credential-named keys inside a serialized
	 * value (for the report's sensitive_subkeys trail).
	 *
	 * @return string[] "parent.child" style key paths.
	 */
	private function sensitiveSubkeys( string $value ): array {
		$trimmed = trim( $value );
		if ( $trimmed === '' || ( $trimmed[0] !== 'a' && $trimmed[0] !== 'O' ) || ! str_contains( $trimmed, ':' ) ) {
			return array();
		}

		// Security: 'allowed_classes' => false prevents PHP Object Injection.
		$data = @unserialize( $trimmed, array( 'allowed_classes' => false ) );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$found = array();
		$this->walkSensitiveKeys( $data, '', $found );

		return $found;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @param string[]                 $found by ref.
	 */
	private function walkSensitiveKeys( array $data, string $prefix, array &$found ): void {
		foreach ( $data as $key => $value ) {
			$keyName = is_string( $key ) ? $key : '';
			$path = $prefix !== '' ? $prefix . '.' . $keyName : $keyName;

			if ( $keyName !== '' && PluginOptionRule::isSensitiveKeyName( $keyName ) ) {
				$found[] = $path;
			}
			if ( is_array( $value ) ) {
				$this->walkSensitiveKeys( $value, $path, $found );
			}
		}
	}

	/**
	 * The sorted union of included sites' active_plugins lists, for
	 * the report.
	 * The lists are serialized arrays.
	 * The destination's own active_plugins is left untouched.
	 *
	 * @param array<int, string> $activePlugins siteId => option_value.
	 *
	 * @return string[]
	 */
	private function activePluginsUnion( array $activePlugins ): array {
		$union = array();
		foreach ( $activePlugins as $value ) {
			$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( ! is_array( $unserialized ) ) {
				continue;
			}
			foreach ( $unserialized as $plugin ) {
				if ( is_string( $plugin ) && $plugin !== '' ) {
					$union[ $plugin ] = true;
				}
			}
		}

		$slugs = array_keys( $union );
		sort( $slugs );

		return $slugs;
	}

	/**
	 * Resolve the destination front-page recommendation for the report.
	 * show_on_front, page_on_front, and page_for_posts never migrate
	 * (destination front-page decision, §7.5).
	 * Their source values are captured here so the post-migration
	 * to-do list can tell the user exactly what to set.
	 * The winner site follows the same rule as conflicts: main_site
	 * when configured and present, otherwise the lowest blog_id.
	 * Page IDs resolve through the posts IdMap.
	 * Returns null when no site has the options.
	 *
	 * @param array<string, array<int, string>> $candidates option_name => siteId => value.
	 *
	 * @return array{blog_id: int, show_on_front: string, page_on_front: int, page_on_front_destination: int|null, page_for_posts: int, page_for_posts_destination: int|null}|null
	 */
	private function resolveFrontPage( array $candidates, MergeConfig $config, IdMap $idMap ): ?array {
		$sites = $candidates['show_on_front'] ?? $candidates['page_on_front'] ?? array();
		if ( $sites === array() ) {
			return null;
		}

		$winner = $config->mainSite ?? null;
		if ( $winner === null || ! isset( $sites[ $winner ] ) ) {
			$winner = min( array_keys( $sites ) );
		}

		$pageOnFront = (int) ( $candidates['page_on_front'][ $winner ] ?? 0 );
		$pageForPosts = (int) ( $candidates['page_for_posts'][ $winner ] ?? 0 );

		return array(
			'blog_id' => $winner,
			'show_on_front' => (string) ( $candidates['show_on_front'][ $winner ] ?? 'posts' ),
			'page_on_front' => $pageOnFront,
			'page_on_front_destination' => $pageOnFront > 0 ? ( $idMap->get( 'post', $winner, $pageOnFront ) ?? null ) : null,
			'page_for_posts' => $pageForPosts,
			'page_for_posts_destination' => $pageForPosts > 0 ? ( $idMap->get( 'post', $winner, $pageForPosts ) ?? null ) : null,
		);
	}

	/**
	 * @return array<string, int> option_name => option_id on the destination.
	 */
	private function destinationOptionIds( Connection $destination, string $optionsTable ): array {
		$ids = array();
		foreach ( $destination->fetchAll( "SELECT option_id, option_name FROM {$optionsTable}" ) as $row ) {
			$ids[ (string) $row['option_name'] ] = (int) $row['option_id'];
		}

		return $ids;
	}

	/**
	 * Insert an option on the destination, or update the existing row.
	 * wp_options.option_name is unique.
	 * A destination install already has defaults for many names.
	 * Returns the destination option_id.
	 *
	 * @param array<string, int>     $destOptionIds
	 * @param OptionsMigrationReport $report
	 */
	private function upsertOption(
		Connection $destination,
		string $optionsTable,
		string $name,
		string $value,
		string $autoload,
		array $destOptionIds,
		array &$report
	): int {
		$existingId = $destOptionIds[ $name ] ?? null;

		if ( $existingId !== null ) {
			$destination->execute(
				"UPDATE {$optionsTable} SET option_value = :value, autoload = :autoload WHERE option_id = :id",
				array(
					'value' => $value,
					'autoload' => $autoload,
					'id' => $existingId,
				)
			);
			++$report['destination_overwrites'];

			return $existingId;
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
	 * Wildcard match used by the never-migrate patterns (`widget_*`,
	 * `theme_mods_*`): trailing `*` = prefix match, otherwise exact.
	 */
	private function matchesPattern( string $name, string $pattern ): bool {
		if ( str_ends_with( $pattern, '*' ) ) {
			return str_starts_with( $name, substr( $pattern, 0, -1 ) );
		}

		return $name === $pattern;
	}
}
