<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

/**
 * A resolved database endpoint: either a TCP host:port pair or a local
 * unix socket (how Local by Flywheel exposes MySQL on macOS/Linux).
 * When `socket` is set it takes precedence over host/port.
 *
 * @package MergeMultisite
 */
final class Endpoint {

	public function __construct(
		public readonly string $host,
		public readonly int $port,
		public readonly ?string $socket = null,
	) {
	}

	public static function hostPort( string $host, int $port ): self {
		return new self( $host, $port );
	}

	public static function socket( string $socketPath ): self {
		return new self( 'localhost', 3306, $socketPath );
	}
}
