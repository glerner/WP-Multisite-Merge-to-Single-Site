<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Config\PluginOptionRule;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\PluginInventory;

/**
 * Cross-checks each site's installed/active plugins against
 * `config/option-keys.php` (PLAN.md §7.5 & §9):
 *
 *  - Installed (active or inactive) plugins with no configured rule at
 *    all are flagged, so nothing is silently dropped.
 *  - Plugins whose configured option-key patterns match data actually
 *    present in `wp_options`, but which are not installed on that
 *    site, are flagged as likely leftover cruft.
 *  - Plugins explicitly marked `needs_adapter` (e.g. Pods) are flagged
 *    if installed, so a dedicated adapter can be built once confirmed.
 *
 * @package MergeMultisite
 */
final class PluginDataCheck implements AuditCheckInterface {

	/**
	 * @param PluginInventory|null $inventory Injected for tests; when null, one is
	 *                                         built from config.source.uploadsPath at run time.
	 */
	public function __construct( private readonly ?PluginInventory $inventory = null ) {
	}

	public function name(): string {
		return 'plugin-data';
	}

	public function description(): string {
		return 'Plugin wp_options data: installed plugins with no option-keys.php rule (won\'t migrate), option rows for uninstalled plugins (likely leftover), and plugins needing a migrator adapter.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$inventory = $this->inventory ?? PluginInventory::fromUploadsPath( $config->source->uploadsPath );

		$networkActive = $inventory->networkActiveSlugs( $source );

		// Must-use plugins are always installed AND active on every
		// site (WordPress force-loads them unconditionally -- there is
		// no active_plugins entry for them at all), so they're folded
		// into every site's installed/active set up front rather than
		// queried per-site like regular plugins.
		$mustUse = $inventory->mustUseSlugs();

		$findings = array();
		$orphaned = array();

		// Per-site installed/active sets, keyed by plugin slug, so the
		// "no rule" / "needs_adapter" warnings can be AGGREGATED into
		// one finding per plugin (with a sorted site list) instead of
		// one repetitive finding per site -- see the user-facing
		// convention described in PLAN.md §9 ("list sorted by plugin,
		// then site ID").
		// Note: 'active_sites' is only ever set when a plugin is
		// active on at least one site -- accessed defensively below
		// via ?? array() for plugins that are installed but never active.
		/** @var array<string, array{installed_sites: int[], active_sites?: int[]}> $pluginSites */
		$pluginSites = array();

		foreach ( $sites as $site ) {
			$installed = array_unique( array( ...$inventory->installedSlugs(), ...$mustUse ) );
			$active    = array_unique( array( ...$networkActive, ...$inventory->activeSlugsForSite( $source, $site->blogId ), ...$mustUse ) );

			foreach ( $installed as $slug ) {
				$pluginSites[ $slug ]['installed_sites'][] = $site->blogId;
				if ( in_array( $slug, $active, true ) ) {
					$pluginSites[ $slug ]['active_sites'][] = $site->blogId;
				}
			}

			$this->findOrphanedPluginData( $source, $config, $site->blogId, $installed, $orphaned );
		}

		if ( $orphaned !== array() ) {
			$findings[] = $this->orphanedDataFinding( $orphaned );
		}

		ksort( $pluginSites );

		$undecided = array();

		foreach ( $pluginSites as $slug => $sitesForPlugin ) {
			$installedSites = $sitesForPlugin['installed_sites'];
			$activeSites    = $sitesForPlugin['active_sites'] ?? array();

			sort( $installedSites );
			sort( $activeSites );

			$rule = $config->pluginOptionRuleFor( $slug );

			if ( $rule === null ) {
				$undecided[ $slug ] = $this->detectPluginFootprint( $source, $config, $slug, $installedSites );
				$siteSummary = implode(
					', ',
					array_map(
						static fn ( int $blogId ): string => sprintf(
							'%d%s',
							$blogId,
							in_array( $blogId, $activeSites, true ) ? ' (active)' : ''
						),
						$installedSites
					)
				);

				$findings[] = AuditFinding::warning(
					$this->name() . '.no-rule',
					sprintf(
						"Plugin \"%s\" on site(s) %s -- no option-keys.php rule; its wp_options data will NOT be migrated. Paste-ready rules grouped under 'plugin-data.undecided' below.",
						$slug,
						$siteSummary
					),
					array(
						'plugin'       => $slug,
						'installed_on' => $installedSites,
						'active_on'    => $activeSites,
					)
				);
				continue;
			}

			if ( $rule->mode === PluginOptionRule::MODE_NEEDS_ADAPTER ) {
				$findings[] = AuditFinding::warning(
					$this->name() . '.needs-adapter',
					sprintf(
						"Plugin \"%s\" on site(s) %s -- needs_adapter (%s); its data can't migrate until an adapter is built. To hide: ['check' => 'plugin-data.needs-adapter', 'plugin' => '%s']",
						$slug,
						implode( ', ', $installedSites ),
						$rule->reason ?? '',
						$slug
					),
					array(
						'plugin'       => $slug,
						'installed_on' => $installedSites,
						'reason'       => $rule->reason,
					)
				);
			}
		}

		if ( $undecided !== array() ) {
			$findings[] = $this->undecidedPluginsFinding( $undecided );
		}

		return $findings;
	}

	/**
	 * Where a plugin actually stores data: wp_options rows, postmeta
	 * keys, and custom DB tables matching the slug's common spellings
	 * ("ai-engine" -> "ai_engine%", "aiengine%", "_ai_engine%").
	 *
	 * @param int[] $blogIds
	 *
	 * @return array{options: string[], postmeta: string[], posts: string[], tables: string[]}
	 */
	private function detectPluginFootprint( Connection $source, MergeConfig $config, string $slug, array $blogIds ): array {
		return array(
			'options'  => $this->detectOptionNames( $source, $slug, $blogIds ),
			'postmeta' => $this->detectMetaKeys( $source, $slug, $blogIds ),
			'posts'    => $this->detectPostTypes( $source, $slug, $blogIds ),
			'tables'   => $this->detectTables( $source, $config, $slug ),
		);
	}

	/**
	 * @param int[] $blogIds
	 *
	 * @return string[] Distinct matching option names.
	 */
	private function detectOptionNames( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$names = array();
		foreach ( $blogIds as $blogId ) {
			$optionsTable = $source->siteTable( 'options', $blogId );
			$rows = $source->fetchAll(
				"SELECT DISTINCT option_name FROM {$optionsTable}
                 WHERE option_name LIKE :underscored OR option_name LIKE :condensed
                 LIMIT 100",
				array(
				'underscored' => $underscored . '%',
				'condensed' => $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$names[ (string) $row['option_name'] ] = true;
			}
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}

	/**
	 * Postmeta keys often start with "_" (hidden meta), so probe both
	 * bare and underscored variants of each spelling.
	 *
	 * @param int[] $blogIds
	 *
	 * @return string[] Distinct matching meta keys.
	 */
	private function detectMetaKeys( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$names = array();
		foreach ( $blogIds as $blogId ) {
			$postMetaTable = $source->siteTable( 'postmeta', $blogId );
			$rows = $source->fetchAll(
				"SELECT DISTINCT meta_key FROM {$postMetaTable}
                 WHERE meta_key LIKE :u1 OR meta_key LIKE :u2
                    OR meta_key LIKE :c1 OR meta_key LIKE :c2
                 LIMIT 100",
				array(
				'u1' => $underscored . '%',
				'u2' => '_' . $underscored . '%',
				'c1' => $condensed . '%',
				'c2' => '_' . $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$names[ (string) $row['meta_key'] ] = true;
			}
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}

	/**
	 * Custom post types embedding the slug ("flamingo" ->
	 * "flamingo_contact"), which is where form-submission and similar
	 * plugins keep their real data.
	 *
	 * @param int[] $blogIds
	 *
	 * @return string[] "post_type xN" entries.
	 */
	private function detectPostTypes( Connection $source, string $slug, array $blogIds ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$counts = array();
		foreach ( $blogIds as $blogId ) {
			$postsTable = $source->siteTable( 'posts', $blogId );
			$rows = $source->fetchAll(
				"SELECT post_type, COUNT(*) AS n FROM {$postsTable}
                 WHERE post_type LIKE :u OR post_type LIKE :c
                 GROUP BY post_type
                 LIMIT 50",
				array(
				'u' => '%' . $underscored . '%',
				'c' => '%' . $condensed . '%',
				)
			);

			foreach ( $rows as $row ) {
				$type = (string) $row['post_type'];
				$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + (int) $row['n'];
			}
		}

		ksort( $counts );

		return array_map(
			static fn ( string $type, int $count ): string => sprintf( '%s x%d', $type, $count ),
			array_keys( $counts ),
			array_values( $counts )
		);
	}

	/**
	 * Custom DB tables whose name contains the slug, e.g. a plugin's
	 * own wp3_maiengine_* tables. Table names are prefix-qualified, so
	 * this is a contains-match, not a prefix-match.
	 *
	 * @return string[]
	 */
	private function detectTables( Connection $source, MergeConfig $config, string $slug ): array {
		$underscored = str_replace( '-', '_', $slug );
		$condensed   = str_replace( '-', '', $slug );

		$rows = $source->fetchAll(
			'SELECT TABLE_NAME FROM information_schema.TABLES '
			. 'WHERE TABLE_SCHEMA = :schema AND (TABLE_NAME LIKE :u OR TABLE_NAME LIKE :c)',
			array(
			'schema' => $config->source->database,
			'u' => '%' . $underscored . '%',
			'c' => '%' . $condensed . '%',
			)
		);

		$names = array_map( static fn ( array $row ): string => (string) $row['TABLE_NAME'], $rows );
		sort( $names );

		return $names;
	}

	/**
	 * Turns detected option names into an option_keys suggestion:
	 * one match stays exact, several collapse to their common prefix
	 * plus "*". Falls back to a "<prefix>*" placeholder.
	 *
	 * @param string[] $names
	 *
	 * @return string[]
	 */
	private function suggestedOptionKeys( array $names ): array {
		if ( $names === array() ) {
			return array( '<prefix>*' );
		}

		if ( count( $names ) === 1 ) {
			return array( $names[0] );
		}

		$lcp = $names[0];
		foreach ( $names as $name ) {
			while ( $lcp !== '' && ! str_starts_with( $name, $lcp ) ) {
				$lcp = substr( $lcp, 0, -1 );
			}
		}

		// Trim a trailing partial word so e.g. "ai_engin" becomes
		// "ai_engine" -- cleaner to read and no less correct.
		if ( $lcp !== '' && ! str_ends_with( $lcp, '_' ) && ! str_ends_with( $lcp, '-' ) ) {
			$lastSeparator = max( (int) strrpos( $lcp, '_' ), (int) strrpos( $lcp, '-' ) );
			if ( $lastSeparator > 0 ) {
				$lcp = substr( $lcp, 0, $lastSeparator + 1 );
			}
		}

		return array( $lcp === '' ? '<prefix>*' : $lcp . '*' );
	}

	/**
	 * One consolidated, paste-ready list of every installed plugin that
	 * has no option-keys.php rule at all -- the "decide these" list.
	 * Rules are grouped by intent so whole blocks can be copied out.
	 * Include lines are only emitted for plugins whose option rows were
	 * actually detected; the rest are listed with where their data (if
	 * any) lives instead.
	 *
	 * @param array<string, array{options: string[], postmeta: string[], posts: string[], tables: string[]}> $undecided
	 *        Plugin slug => detected footprint.
	 */
	private function undecidedPluginsFinding( array $undecided ): AuditFinding {
		$include = array();
		$elsewhere = array();
		$exclude = array();
		$suppress = array();

		foreach ( $undecided as $slug => $footprint ) {
			if ( $footprint['options'] !== array() ) {
				$keys = $this->suggestedOptionKeys( $footprint['options'] );
				$sample = array_slice( $footprint['options'], 0, 3 );
				$include[] = sprintf(
					"    '%s' => ['mode' => 'include', 'option_keys' => %s],  // found: %s%s",
					$slug,
					"['" . implode( "', '", $keys ) . "']",
					implode( ', ', $sample ),
					count( $footprint['options'] ) > 3
						? sprintf( ' (+%d more)', count( $footprint['options'] ) - 3 )
						: ''
				);
			} else {
				$parts = array();
				if ( $footprint['posts'] !== array() ) {
					$parts[] = sprintf( 'post types: %s', implode( ', ', $footprint['posts'] ) );
				}
				if ( $footprint['postmeta'] !== array() ) {
					$parts[] = sprintf(
						'postmeta keys: %s%s',
						implode( ', ', array_slice( $footprint['postmeta'], 0, 3 ) ),
						count( $footprint['postmeta'] ) > 3
							? sprintf( ' (+%d more)', count( $footprint['postmeta'] ) - 3 )
							: ''
					);
				}
				if ( $footprint['tables'] !== array() ) {
					$parts[] = sprintf( 'tables: %s', implode( ', ', $footprint['tables'] ) );
				}
				$elsewhere[] = sprintf(
					'    %s: %s',
					$slug,
					$parts === array() ? 'nothing found in wp_options, posts, postmeta, or custom tables' : implode( ' | ', $parts )
				);
			}

			$exclude[]  = sprintf( "    '%s' => ['mode' => 'exclude'],", $slug );
			$suppress[] = sprintf( "    ['check' => 'plugin-data.no-rule', 'plugin' => '%s'],", $slug );
		}

		$message = sprintf(
			'%d installed plugin(s) have NO option-keys.php rule -- their wp_options data will not migrate.',
			count( $undecided )
		);
		if ( $include !== array() ) {
			$message .= "\n  To keep a plugin's wp_options data, paste its 'include' line into config/option-keys.php"
				. "\n  (option prefixes detected from its sites; verify before keeping):"
				. "\n" . implode( "\n", $include );
		}
		if ( $elsewhere !== array() ) {
			$message .= "\n  No wp_options rows detected -- where the plugin's data actually lives:"
				. "\n" . implode( "\n", $elsewhere );
		}
		$message .= "\n  To never migrate it, paste its line into config/option-keys.php:"
			. "\n" . implode( "\n", $exclude )
			. "\n  To hide it without deciding, paste into 'suppressions' in config.php:"
			. "\n" . implode( "\n", $suppress );

		return AuditFinding::info(
			$this->name() . '.undecided',
			$message,
			array(
				'plugins' => array_keys( $undecided ),
				'footprints' => $undecided,
			)
		);
	}

	/**
	 * Detects wp_options rows on a site that match configured rules for
	 * plugins which are not installed there -- likely leftover from a
	 * removed plugin. Matches are accumulated into $orphaned keyed by
	 * PATTERN (not rule slug), so two rules sharing a pattern (e.g.
	 * "bbq*" listed for both "block-bad-queries" and "bbq") produce one
	 * row with both slugs instead of a duplicate finding.
	 *
	 * Rules with mode "exclude" are skipped entirely: their data is
	 * intentionally never migrated, so reporting leftover rows for
	 * them is noise.
	 *
	 * @param string[] $installedSlugs
	 * @param array    $orphaned       Match accumulator, keyed by pattern then
	 *                                 blog_id: ['slugs' => slug set, 'rows' => int,
	 *                                 'unique_names' => int].
	 */
	private function findOrphanedPluginData( Connection $source, MergeConfig $config, int $blogId, array $installedSlugs, array &$orphaned ): void {
		$optionsTable = $source->siteTable( 'options', $blogId );

		foreach ( $config->pluginOptionRules as $slug => $rule ) {
			if (
				in_array( $slug, $installedSlugs, true )
				|| $rule->optionKeys === array()
				|| $rule->mode === PluginOptionRule::MODE_EXCLUDE
			) {
				continue;
			}

			foreach ( $rule->optionKeys as $pattern ) {
				if ( isset( $orphaned[ $pattern ][ $blogId ] ) ) {
					$orphaned[ $pattern ][ $blogId ]['slugs'][ $slug ] = true;
					continue;
				}

				$likePattern = str_ends_with( $pattern, '*' ) ? substr( $pattern, 0, -1 ) . '%' : $pattern;

				$counts = $source->fetchOne(
					"SELECT COUNT(*) AS rows_total, COUNT(DISTINCT option_name) AS unique_names
                     FROM {$optionsTable} WHERE option_name LIKE :pattern",
					array( 'pattern' => $likePattern )
				);

				if ( $counts !== null && (int) $counts['rows_total'] > 0 ) {
					$orphaned[ $pattern ][ $blogId ] = array(
						'slugs'        => array( $slug => true ),
						'rows'         => (int) $counts['rows_total'],
						'unique_names' => (int) $counts['unique_names'],
					);
					break;
				}
			}
		}
	}

	/**
	 * Renders all orphaned plugin-option rows as ONE finding whose
	 * message is a Markdown table -- the shared explanation appears
	 * once instead of per row.
	 *
	 * @param array $orphaned Accumulator from findOrphanedPluginData().
	 */
	private function orphanedDataFinding( array $orphaned ): AuditFinding {
		ksort( $orphaned );

		$table = array(
			'  | Plugin rule(s) | Pattern | Site | Option rows | Unique option names |',
			'  |---|---|---|---|---|',
		);
		$rows = array();

		foreach ( $orphaned as $pattern => $perSite ) {
			ksort( $perSite );
			foreach ( $perSite as $blogId => $data ) {
				$slugs = implode( ', ', array_keys( $data['slugs'] ) );
				$table[] = sprintf(
					'  | %s | %s | %d | %d | %d |',
					$slugs,
					$pattern,
					$blogId,
					$data['rows'],
					$data['unique_names']
				);
				$rows[] = array(
					'pattern'      => $pattern,
					'plugins'      => array_keys( $data['slugs'] ),
					'blog_id'      => $blogId,
					'rows'         => $data['rows'],
					'unique_names' => $data['unique_names'],
				);
			}
		}

		return AuditFinding::info(
			$this->name() . '.orphaned-data',
			"wp_options data exists for plugins NOT INSTALLED on that site (likely leftover from removed plugins).\n"
			. "  These plugins already have option-keys.php rules. To keep the data, leave the rule as 'include';\n"
			. "  to never migrate it, change its 'mode' to 'exclude' there and this row stops appearing.\n"
			. "  (To hide one row without deciding, add to 'suppressions' in config.php:\n"
			. "  ['check' => 'plugin-data.orphaned-data', 'plugin' => '<slug>'])\n"
			. "\n"
			. implode( "\n", $table ),
			array( 'rows' => $rows )
		);
	}
}
