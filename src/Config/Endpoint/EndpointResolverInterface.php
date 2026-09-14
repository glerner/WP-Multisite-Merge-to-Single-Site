<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

use MergeMultisite\Config\ConfigException;

/**
 * Turns the "connection" spec of a config.php database section into a
 * concrete Endpoint. Implementations exist for static host:port config,
 * Lando (port changes every container rebuild) and Local by Flywheel
 * (unix socket). To add another source of connection info, implement
 * this interface and either register it in EndpointResolvers::DRIVERS
 * or point "connection"."driver" at your fully-qualified class name.
 *
 * @package MergeMultisite
 */
interface EndpointResolverInterface {

	/**
	 * @param array<string, mixed> $spec      The "connection" array from a
	 *                                        config.php database section.
	 * @param array<string, mixed> $dbSection The full "source"/"destination"
	 *                                        section, for context like
	 *                                        "uploads_path" or static
	 *                                        host/port fallbacks.
	 *
	 * @throws ConfigException When the endpoint cannot be determined.
	 */
	public function resolve( array $spec, array $dbSection ): Endpoint;
}
