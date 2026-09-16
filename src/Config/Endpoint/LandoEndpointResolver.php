<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

use MergeMultisite\Config\ConfigException;

/**
 * Resolves host/port by asking Lando, because Lando reassigns the
 * database container's published host port every time the container is
 * recreated (restart, rebuild, Docker restart). A port hardcoded in
 * config.php therefore goes stale regularly.
 *
 * Spec keys (all optional except in the failure cases described):
 *   - "service":      Lando service name, default "database".
 *   - "project_path": directory containing the project's .lando.yml.
 *                     When omitted, derived by walking up from
 *                     "uploads_path" looking for .lando.yml.
 *   - "lando_binary": path to the lando CLI when it is not on PATH.
 *
 * @package MergeMultisite
 */
final class LandoEndpointResolver implements EndpointResolverInterface {

	/**
	 * @var callable(array<int, string>, string): array{0: int, 1: string}
	 */
	private $commandRunner;

	/**
	 * @param callable(array<int, string>, string): array{0: int, 1: string}|null $commandRunner
	 *        Runs a command in a working directory, returning
	 *        [exit code, combined output]. Injectable for tests.
	 */
	public function __construct( ?callable $commandRunner = null ) {
		$this->commandRunner = $commandRunner ?? $this->defaultRunner( ... );
	}

	public function resolve( array $spec, array $dbSection ): Endpoint {
		$service = (string) ( $spec['service'] ?? 'database' );
		$projectPath = $this->projectPath( $spec, $dbSection );
		$binary = $this->landoBinary( $spec );

		[ $exitCode, $output ] = ( $this->commandRunner )( array( $binary, 'info', '--format=json' ), $projectPath );

		if ( $exitCode !== 0 ) {
			throw new ConfigException(
				sprintf(
					'`lando info` failed in "%s" (exit %d): %s' . PHP_EOL
					. 'Is the app running? Try `lando start` in that directory.',
					$projectPath,
					$exitCode,
					trim( $output )
				)
			);
		}

		$services = json_decode( $output, true );
		if ( ! is_array( $services ) ) {
			throw new ConfigException(
				sprintf( '`lando info --format=json` in "%s" did not return JSON; got: %s', $projectPath, trim( $output ) )
			);
		}

		foreach ( $services as $serviceInfo ) {
			if ( ! is_array( $serviceInfo ) || ( $serviceInfo['service'] ?? null ) !== $service ) {
				continue;
			}

			$external = $serviceInfo['external_connection'] ?? null;
			if ( is_array( $external ) && isset( $external['port'] ) ) {
				return Endpoint::hostPort(
					(string) ( $external['host'] ?? '127.0.0.1' ),
					(int) $external['port']
				);
			}

			throw new ConfigException(
				sprintf(
					'Lando service "%s" has no "external_connection" port, so nothing on the host can reach it. '
					. 'Check `lando info` output for that service -- the database likely needs port forwarding '
					. 'enabled in .lando.yml (e.g. "database: { portforward: true }").',
					$service
				)
			);
		}

		$serviceNames = array_filter(
			array_map(
				static fn ( $s ) => is_array( $s ) && isset( $s['service'] ) ? (string) $s['service'] : null,
				$services
			)
		);

		throw new ConfigException(
			sprintf(
				'No Lando service named "%s" found in project "%s". Services present: %s.',
				$service,
				$projectPath,
				implode( ', ', $serviceNames )
			)
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $dbSection
	 *
	 * @throws ConfigException When no .lando.yml can be located.
	 */
	private function projectPath( array $spec, array $dbSection ): string {
		if ( ! empty( $spec['project_path'] ) ) {
			return (string) $spec['project_path'];
		}

		$dir = (string) ( $dbSection['uploads_path'] ?? '' );
		for ( $i = 0; $i < 8 && $dir !== '' && $dir !== dirname( $dir ); $i++ ) {
			if ( is_file( $dir . '/.lando.yml' ) ) {
				return $dir;
			}
			$dir = dirname( $dir );
		}

		throw new ConfigException(
			'"connection"."driver" is "lando" but no "project_path" is set, and no .lando.yml was found '
			. 'walking up from "uploads_path". Set "project_path" to the directory containing .lando.yml.'
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 *
	 * @throws ConfigException When the lando CLI cannot be found.
	 */
	private function landoBinary( array $spec ): string {
		if ( ! empty( $spec['lando_binary'] ) ) {
			return (string) $spec['lando_binary'];
		}

		$onPath = trim( (string) shell_exec( 'command -v lando 2>/dev/null' ) );
		if ( $onPath !== '' ) {
			return $onPath;
		}

		$homeEnv = getenv( 'HOME' );
		$home = $homeEnv === false ? '' : $homeEnv;
		foreach ( array( $home . '/.lando/bin/lando', '/usr/local/bin/lando', '/usr/bin/lando' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		throw new ConfigException(
			'"connection"."driver" is "lando" but the "lando" command was not found on PATH '
			. '(also checked ~/.lando/bin and /usr/local/bin). Set "lando_binary" in the "connection" spec.'
		);
	}

	/**
	 * @param array<int, string> $command
	 *
	 * @return array{0: int, 1: string}
	 */
	private function defaultRunner( array $command, string $cwd ): array {
		$escaped = implode( ' ', array_map( 'escapeshellarg', $command ) );
		$lines = array();
		$exitCode = 0;
		exec( sprintf( 'cd %s && %s 2>&1', escapeshellarg( $cwd ), $escaped ), $lines, $exitCode );

		return array( $exitCode, implode( "\n", $lines ) );
	}
}
