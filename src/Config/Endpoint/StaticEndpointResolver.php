<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

use MergeMultisite\Config\ConfigException;

/**
 * The default resolver: use the "host"/"port" (and optional
 * "unix_socket") written literally in config.php.
 *
 * @package MergeMultisite
 */
final class StaticEndpointResolver implements EndpointResolverInterface {

	public function resolve( array $spec, array $dbSection ): Endpoint {
		if ( empty( $dbSection['host'] ) && empty( $dbSection['unix_socket'] ) ) {
			throw new ConfigException(
				'"connection"."driver" is "static" but the database section sets neither "host" nor "unix_socket".'
			);
		}

		return new Endpoint(
			(string) ( $dbSection['host'] ?? 'localhost' ),
			(int) ( $dbSection['port'] ?? 3306 ),
			isset( $dbSection['unix_socket'] ) ? (string) $dbSection['unix_socket'] : null
		);
	}
}
