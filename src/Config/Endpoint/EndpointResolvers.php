<?php

declare(strict_types=1);

namespace MergeMultisite\Config\Endpoint;

use MergeMultisite\Config\ConfigException;

/**
 * Registry mapping "connection"."driver" names from config.php to
 * resolver implementations.
 *
 * To use a custom resolver without touching this map, set
 * "connection"."driver" to the fully-qualified class name of a class
 * implementing EndpointResolverInterface (config.php is plain PHP, so
 * it can `require` the file holding that class first).
 *
 * @package MergeMultisite
 */
final class EndpointResolvers {

	/**
	 * @var array<string, class-string<EndpointResolverInterface>>
	 */
	private const DRIVERS = array(
		'static' => StaticEndpointResolver::class,
		'lando'  => LandoEndpointResolver::class,
		'local'  => LocalEndpointResolver::class,
	);

	/**
	 * @throws ConfigException When the driver name is unknown.
	 */
	public static function forDriver( string $driver ): EndpointResolverInterface {
		if ( isset( self::DRIVERS[ $driver ] ) ) {
			$class = self::DRIVERS[ $driver ];
			return new $class();
		}

		if ( is_subclass_of( $driver, EndpointResolverInterface::class, true ) ) {
			return new $driver();
		}

		throw new ConfigException(
			sprintf(
				'Unknown connection driver "%s". Known drivers: %s -- or set "driver" to the class name '
				. 'of your own %s implementation.',
				$driver,
				implode( ', ', array_keys( self::DRIVERS ) ),
				EndpointResolverInterface::class
			)
		);
	}
}
