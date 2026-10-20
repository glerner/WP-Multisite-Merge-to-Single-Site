<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\PluginInventory;

/**
 * Checks installed/active plugins against the wordpress.org plugin
 * directory, so the destination plugin list is never silently built
 * from slugs that cannot be reinstalled there.
 *
 * A slug found on wordpress.org is a normal directory install and
 * stays silent. A slug on the 'premium_plugins' allowlist in
 * config/plugin-roles.php is a known premium/custom plugin and also
 * stays silent. Anything left over is "installed from somewhere else
 * and we don't recognize it" and gets flagged once, aggregated.
 *
 * The slug universe combines both sources of truth:
 *  - the filesystem (`wp-content/plugins` folders, via
 *    PluginInventory::installedSlugs()), and
 *  - the database (per-site `active_plugins` plus network-active
 *    plugins in `wp_sitemeta`).
 * A plugin that is DB-active but missing from disk is still checked,
 * so deleted-but-configured plugins are not missed.
 *
 * Must-use plugins are excluded: they are custom code loaded
 * unconditionally, never wp.org slugs, so checking them would be
 * guaranteed noise.
 *
 * The network call is one request per slug to api.wordpress.org and is
 * isolated behind an injectable lookup, so tests stay offline and a
 * network failure downgrades the check to an info finding instead of
 * crashing the audit.
 *
 * @package MergeMultisite
 */
final class PluginRepoCheck implements AuditCheckInterface {

	private const API_URL = 'https://api.wordpress.org/plugins/info/1.2/';

	/**
	 * @param PluginInventory|null $inventory       Injected for tests; when null,
	 *                                               built from config.source.uploadsPath.
	 * @param \Closure|null        $directoryLookup Injected for tests; when null,
	 *                                               queries api.wordpress.org.
	 *                                               Signature: (string[] $slugs): ?string[] --
	 *                                               the subset found in the wp.org directory,
	 *                                               or null when the lookup could not run.
	 */
	public function __construct(
		private readonly ?PluginInventory $inventory = null,
		private readonly ?\Closure $directoryLookup = null,
	) {
	}

	public function name(): string {
		return 'plugin-repo';
	}

	public function description(): string {
		return 'Installed/active plugins not found in the wordpress.org directory and not on the premium_plugins allowlist (installed from somewhere else; they need a manual install on the destination).';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$inventory = $this->inventory ?? PluginInventory::fromUploadsPath( $config->source->uploadsPath );

		// Folder slugs union database slugs: installedSlugs() sees what
		// is on disk, the DB sees what is switched on even if files are
		// gone.
		$slugs     = array_fill_keys( $inventory->installedSlugs(), true );
		$activeSites = array();

		$networkActive = $inventory->networkActiveSlugs( $source );
		foreach ( $networkActive as $slug ) {
			$slugs[ $slug ] = true;
		}

		foreach ( $sites as $site ) {
			foreach ( $inventory->activeSlugsForSite( $source, $site->blogId ) as $slug ) {
				$slugs[ $slug ] = true;
				$activeSites[ $slug ][] = $site->blogId;
			}
			foreach ( $networkActive as $slug ) {
				$activeSites[ $slug ][] = $site->blogId;
			}
		}

		$slugs = array_keys( $slugs );
		sort( $slugs );

		// Must-use plugins are custom code (WordPress force-loads them);
		// they are never wp.org slugs, so checking them is pure noise.
		$slugs = array_values( array_diff( $slugs, $inventory->mustUseSlugs() ) );

		// Premium/custom plugins the operator already knows about:
		// silence them exactly like wp.org-directory plugins.
		$allowlist = array();
		foreach ( (array) ( $config->pluginRoles['premium_plugins'] ?? array() ) as $slug ) {
			$allowlist[ (string) $slug ] = true;
		}
		$toCheck = array_values( array_diff( $slugs, array_keys( $allowlist ) ) );

		if ( $toCheck === array() ) {
			return array();
		}

		$lookup = $this->directoryLookup ?? $this->wpOrgLookup( ... );
		$inRepo = $lookup( $toCheck );

		if ( $inRepo === null ) {
			return array(
				AuditFinding::info(
					$this->name() . '.skipped',
					'wordpress.org directory check skipped: api.wordpress.org could not be reached (offline or blocked).',
				),
			);
		}

		$unknown = array_values( array_diff( $toCheck, $inRepo ) );

		if ( $unknown === array() ) {
			return array();
		}

		$lines = array();
		foreach ( $unknown as $slug ) {
			$sites = $activeSites[ $slug ] ?? array();
			$lines[] = sprintf(
				'    %s%s',
				$slug,
				$sites !== array() ? ' (active on site(s) ' . implode( ', ', array_unique( $sites ) ) . ')' : ' (installed, not active anywhere)'
			);
		}

		return array(
			AuditFinding::warning(
				$this->name() . '.unknown',
				sprintf(
					"%d plugin(s) are not in the wordpress.org directory and not on the premium_plugins allowlist -- installed from somewhere else and not recognized.\nThey need a manual install on the destination (not `wp plugin install`).\n  If one is a known premium/custom plugin, add its slug to 'premium_plugins' in config/plugin-roles.php to silence it:\n%s",
					count( $unknown ),
					implode( "\n", $lines )
				),
				array(
					'plugins'    => $unknown,
					'active_on'  => $activeSites,
					'checked'    => count( $toCheck ),
					'in_repo'    => count( $inRepo ),
					'allowlist'  => count( $allowlist ),
				)
			),
		);
	}

	/**
	 * The real directory lookup: one api.wordpress.org request per slug.
	 *
	 * The endpoint returns a JSON plugin object for a known slug and
	 * HTTP 404 for an unknown one, so a missing slug is a clean signal.
	 * Any transport-level failure (DNS, timeout, connection refused)
	 * aborts the whole check by returning null, because a partial
	 * result would silently mislabel unfetched plugins as unknown.
	 *
	 * @param string[] $slugs
	 *
	 * @return string[]|null Slugs confirmed to exist on wordpress.org,
	 *                       or null when the network could not be
	 *                       reached.
	 */
	private function wpOrgLookup( array $slugs ): ?array {
		$found  = array();
		$failed = false;

		foreach ( $slugs as $slug ) {
			$url    = self::API_URL . '?action=plugin_information&slug=' . rawurlencode( $slug )
				. '&request[fields][sections]=0&request[fields][versions]=0'
				. '&request[fields][screenshots]=0&request[fields][tags]=0';
			$status = $this->fetchUrl( $url );

			if ( $status === 'fail' ) {
				$failed = true;
				break;
			}

			if ( $status === 'found' ) {
				$found[] = $slug;
			}
		}

		return $failed ? null : $found;
	}

	/**
	 * Fetches one URL and classifies the result:
	 * 'found' (2xx with a JSON body), 'missing' (HTTP 404), or
	 * 'fail' (no HTTP response at all -- transport failure).
	 */
	private function fetchUrl( string $url ): string {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 15,
					'ignore_errors' => true,
					'user_agent'    => 'merge-multisite-audit',
				),
			)
		);

		// $http_response_header is populated in the local scope by the
		// HTTP wrapper, including on 4xx/5xx thanks to ignore_errors.
		// It is pre-cleared because the wrapper leaves it untouched on a
		// transport failure, which would otherwise leak the previous
		// request's status into this one.
		$http_response_header = array();
		$body                 = @file_get_contents( $url, false, $context );

		if ( $http_response_header === array() ) {
			return 'fail';
		}

		$statusLine = (string) $http_response_header[0];
		if ( str_contains( $statusLine, '404' ) ) {
			return 'missing';
		}

		return is_string( $body ) && $body !== '' && $body !== 'false' && $body !== 'null' ? 'found' : 'missing';
	}
}
