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

			$findings = array( ...$findings, ...$this->findOrphanedPluginData( $source, $config, $site->blogId, $installed ) );
		}

		ksort( $pluginSites );

		foreach ( $pluginSites as $slug => $sitesForPlugin ) {
			$installedSites = $sitesForPlugin['installed_sites'];
			$activeSites    = $sitesForPlugin['active_sites'] ?? array();

			sort( $installedSites );
			sort( $activeSites );

			$rule = $config->pluginOptionRuleFor( $slug );

			if ( $rule === null ) {
				$findings[] = AuditFinding::warning(
					$this->name() . '.no-rule',
					sprintf(
						'Plugin "%s" is installed on %d site(s) (%s) but has no rule in option-keys.php -- its data will not be migrated automatically.',
						$slug,
						count( $installedSites ),
						implode(
							', ',
							array_map(
								static fn ( int $blogId ): string => sprintf(
									'site %d%s',
									$blogId,
									in_array( $blogId, $activeSites, true ) ? ' (active)' : ''
								),
								$installedSites
							)
						)
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
						'Plugin "%s" is installed on %d site(s) (%s) and is marked "needs_adapter" (%s) -- confirm whether a dedicated migrator adapter is needed.',
						$slug,
						count( $installedSites ),
						implode( ', ', $installedSites ),
						$rule->reason ?? ''
					),
					array(
						'plugin'       => $slug,
						'installed_on' => $installedSites,
						'reason'       => $rule->reason,
					)
				);
			}
		}

		return $findings;
	}

	/**
	 * @param string[] $installedSlugs
	 *
	 * @return AuditFinding[]
	 */
	private function findOrphanedPluginData( Connection $source, MergeConfig $config, int $blogId, array $installedSlugs ): array {
		$optionsTable = $source->siteTable( 'options', $blogId );
		$findings     = array();

		foreach ( $config->pluginOptionRules as $slug => $rule ) {
			if ( in_array( $slug, $installedSlugs, true ) || $rule->optionKeys === array() ) {
				continue;
			}

			foreach ( $rule->optionKeys as $pattern ) {
				$likePattern = str_ends_with( $pattern, '*' ) ? substr( $pattern, 0, -1 ) . '%' : $pattern;

				$count = (int) $source->fetchScalar(
					"SELECT COUNT(*) FROM {$optionsTable} WHERE option_name LIKE :pattern",
					array( 'pattern' => $likePattern )
				);

				if ( $count > 0 ) {
					$findings[] = AuditFinding::info(
						$this->name() . '.orphaned-data',
						sprintf(
							'Site %d has %d option row(s) matching "%s" (plugin "%s"), but that plugin is not installed -- likely leftover from a removed plugin.',
							$blogId,
							$count,
							$pattern,
							$slug
						),
						array(
							'blog_id' => $blogId,
							'plugin'  => $slug,
							'pattern' => $pattern,
							'count'   => $count,
						)
					);
					break;
				}
			}
		}

		return $findings;
	}
}
