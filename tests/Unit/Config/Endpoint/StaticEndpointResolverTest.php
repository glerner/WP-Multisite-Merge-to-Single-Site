<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config\Endpoint;

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\Endpoint\StaticEndpointResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( StaticEndpointResolver::class )]
final class StaticEndpointResolverTest extends TestCase {

	public function testUsesLiteralHostAndPort(): void {
		$endpoint = ( new StaticEndpointResolver() )->resolve(
			array( 'driver' => 'static' ),
			array(
				'host' => 'db.internal',
				'port' => 3307,
			)
		);

		self::assertSame( 'db.internal', $endpoint->host );
		self::assertSame( 3307, $endpoint->port );
		self::assertNull( $endpoint->socket );
	}

	public function testDefaultsPortTo3306(): void {
		$endpoint = ( new StaticEndpointResolver() )->resolve(
			array( 'driver' => 'static' ),
			array( 'host' => '127.0.0.1' )
		);

		self::assertSame( 3306, $endpoint->port );
	}

	public function testPassesThroughUnixSocket(): void {
		$endpoint = ( new StaticEndpointResolver() )->resolve(
			array( 'driver' => 'static' ),
			array( 'unix_socket' => '/tmp/mysqld.sock' )
		);

		self::assertSame( '/tmp/mysqld.sock', $endpoint->socket );
	}

	public function testThrowsWhenNeitherHostNorSocketSet(): void {
		$this->expectException( ConfigException::class );

		( new StaticEndpointResolver() )->resolve( array( 'driver' => 'static' ), array() );
	}
}
