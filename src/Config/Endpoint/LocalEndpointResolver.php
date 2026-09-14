<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

use MergeMultisite\Config\ConfigException;

/**
 * Resolves the endpoint for a site running under Local (Local by
 * Flywheel / localwp.com). Local keeps per-site connection info in
 * its sites.json and -- on macOS and Linux -- exposes MySQL over a
 * unix socket that only exists while the site is running; TCP ports
 * are a Windows-era leftover.
 *
 * Spec keys:
 *   - "site":       the site's name as shown in Local, OR
 *   - "site_path":  the site's directory, e.g. "~/Local Sites/mysite".
 *                   At least one of the two is required.
 *   - "service":    Local service name, default "mysql".
 *   - "sites_json": absolute path to Local's sites.json when it is not
 *                   in the default location (~/.config/Local on Linux,
 *                   ~/Library/Application Support/Local on macOS).
 *
 * @package MergeMultisite
 */
final class LocalEndpointResolver implements EndpointResolverInterface {

	public function resolve( array $spec, array $dbSection ): Endpoint {
		$service = (string) ( $spec['service'] ?? 'mysql' );
		$sitesJsonPath = $this->sitesJsonPath( $spec );

		$site = $this->findSite( $spec, $sitesJsonPath );
		$siteId = (string) ( $site['id'] ?? '' );

		// Preferred: the socket Local creates while the site is running.
		if ( $siteId !== '' ) {
			$socket = dirname( $sitesJsonPath ) . '/run/' . $siteId . '/mysql/mysqld.sock';
			if ( file_exists( $socket ) ) {
				return Endpoint::socket( $socket );
			}
		}

		// Fallback: a published TCP port (Windows-era Local, or a port map).
		$port = $this->extractPort( $site['services'][ $service ] ?? null );
		if ( $port !== null ) {
			return Endpoint::hostPort( '127.0.0.1', $port );
		}

		throw new ConfigException(
			sprintf(
				'Site was found in "%s", but no mysql socket at "run/%s/mysql/mysqld.sock" '
				. 'and no published port for service "%s". Is the site running in Local? '
				. 'On macOS/Linux the socket only exists while the site is started.',
				$sitesJsonPath,
				$siteId !== '' ? $siteId : '?',
				$service
			)
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 *
	 * @throws ConfigException When sites.json cannot be located.
	 */
	private function sitesJsonPath( array $spec ): string {
		if ( ! empty( $spec['sites_json'] ) ) {
			$path = $this->expandHome( (string) $spec['sites_json'] );
			if ( is_file( $path ) ) {
				return $path;
			}
			throw new ConfigException( sprintf( '"connection"."sites_json" points at "%s", which does not exist.', $path ) );
		}

		$homeEnv = getenv( 'HOME' );
		$home = $homeEnv === false ? '' : $homeEnv;
		foreach ( array(
			$home . '/.config/Local/sites.json',
			$home . '/Library/Application Support/Local/sites.json',
		) as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		throw new ConfigException(
			'"connection"."driver" is "local" but Local\'s sites.json was not found in the default locations '
			. '(~/.config/Local or ~/Library/Application Support/Local). Set "sites_json" explicitly.'
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ConfigException When no matching site is found.
	 */
	private function findSite( array $spec, string $sitesJsonPath ): array {
		$raw = json_decode( (string) file_get_contents( $sitesJsonPath ), true );
		if ( ! is_array( $raw ) ) {
			throw new ConfigException( sprintf( '"%s" could not be parsed as JSON.', $sitesJsonPath ) );
		}

		$wantName = isset( $spec['site'] ) ? (string) $spec['site'] : null;
		$wantPath = isset( $spec['site_path'] ) ? rtrim( $this->expandHome( (string) $spec['site_path'] ), '/' ) : null;

		if ( $wantName === null && $wantPath === null ) {
			throw new ConfigException(
				'"connection"."driver" is "local" but neither "site" (name) nor "site_path" is set.'
			);
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( $wantName !== null && ( $entry['name'] ?? null ) === $wantName ) {
				return $entry;
			}
			if ( $wantPath !== null && rtrim( (string) ( $entry['path'] ?? '' ), '/' ) === $wantPath ) {
				return $entry;
			}
		}

		$siteNames = array_filter(
			array_map(
				static fn ( $s ) => is_array( $s ) && isset( $s['name'] ) ? (string) $s['name'] : null,
				$raw
			)
		);

		throw new ConfigException(
			sprintf(
				'No site matching %s found in "%s". Sites present: %s.',
				$wantName !== null ? sprintf( 'name "%s"', $wantName ) : sprintf( 'path "%s"', (string) $wantPath ),
				$sitesJsonPath,
				implode( ', ', $siteNames )
			)
		);
	}

	/**
	 * Pull a published TCP port out of a Local service entry, tolerating
	 * the different shapes sites.json has used across Local versions:
	 * "port": 10005, or "ports": {"MYSQL": [10005]} / {"MYSQL": 10005}.
	 */
	private function extractPort( mixed $serviceEntry ): ?int {
		if ( ! is_array( $serviceEntry ) ) {
			return null;
		}

		if ( isset( $serviceEntry['port'] ) && is_numeric( $serviceEntry['port'] ) ) {
			return (int) $serviceEntry['port'];
		}

		$ports = $serviceEntry['ports'] ?? null;
		if ( is_array( $ports ) ) {
			foreach ( $ports as $value ) {
				if ( is_numeric( $value ) ) {
					return (int) $value;
				}
				if ( is_array( $value ) && isset( $value[0] ) && is_numeric( $value[0] ) ) {
					return (int) $value[0];
				}
			}
		}

		return null;
	}

	private function expandHome( string $path ): string {
		if ( str_starts_with( $path, '~/' ) ) {
			$homeEnv = getenv( 'HOME' );
			return ( $homeEnv === false ? '' : $homeEnv ) . substr( $path, 1 );
		}
		return $path;
	}
}
