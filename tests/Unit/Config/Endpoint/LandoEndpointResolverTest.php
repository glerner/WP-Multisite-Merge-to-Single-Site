<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config\Endpoint;

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\Endpoint\LandoEndpointResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( LandoEndpointResolver::class )]
final class LandoEndpointResolverTest extends TestCase {

	/**
	 * A realistic `lando info --format=json` payload: the database
	 * service's external_connection host/port is what changes on every
	 * container rebuild.
	 */
	private const LANDO_INFO = <<<'JSON'
[
    {"service": "appserver", "type": "php", "urls": ["https://app.lndo.site"]},
    {"service": "database", "type": "mariadb",
     "internal_connection": {"host": "database", "port": 3306},
     "external_connection": {"host": "localhost", "port": "32770"}}
]
JSON;

	/**
	 * Spec values that keep the resolver fully deterministic in tests:
	 * no PATH lookup for the binary, no filesystem walk for .lando.yml.
	 *
	 * @return array<string, mixed>
	 */
	private function spec(): array {
		return array(
			'driver'       => 'lando',
			'lando_binary' => '/usr/bin/lando',
			'project_path' => '/tmp/lando-project',
		);
	}

	public function testReadsExternalConnectionPort(): void {
		$resolver = new LandoEndpointResolver(
			static fn ( array $command, string $cwd ): array => array( 0, self::LANDO_INFO )
		);

		$endpoint = $resolver->resolve( $this->spec(), array() );

		self::assertSame( 'localhost', $endpoint->host );
		self::assertSame( 32770, $endpoint->port );
		self::assertNull( $endpoint->socket );
	}

	public function testRunsLandoInfoInTheProjectDirectory(): void {
		$seen = array();
		$resolver = new LandoEndpointResolver(
			static function ( array $command, string $cwd ) use ( &$seen ): array {
				$seen = array( $command, $cwd );
				return array( 0, self::LANDO_INFO );
			}
		);

		$resolver->resolve( $this->spec(), array() );

		self::assertSame( '/tmp/lando-project', $seen[1] );
		self::assertSame( 'info', $seen[0][1] );
	}

	public function testThrowsWhenLandoInfoFails(): void {
		$resolver = new LandoEndpointResolver(
			static fn ( array $command, string $cwd ): array => array( 1, 'app is not running' )
		);

		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'lando start' );

		$resolver->resolve( $this->spec(), array() );
	}

	public function testThrowsWhenServiceNotFound(): void {
		$resolver = new LandoEndpointResolver(
			static fn ( array $command, string $cwd ): array => array( 0, self::LANDO_INFO )
		);

		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'mariadb' );

		$resolver->resolve( $this->spec() + array( 'service' => 'mariadb' ), array() );
	}

	public function testThrowsWhenServiceHasNoExternalConnection(): void {
		$resolver = new LandoEndpointResolver(
			static fn ( array $command, string $cwd ): array => array(
				0,
				'[{"service": "database", "type": "mariadb"}]',
			)
		);

		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'external_connection' );

		$resolver->resolve( $this->spec(), array() );
	}

	public function testThrowsWhenNoProjectPathAndNoLandoYmlFound(): void {
		$resolver = new LandoEndpointResolver(
			static fn ( array $command, string $cwd ): array => array( 0, self::LANDO_INFO )
		);

		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'project_path' );

		$resolver->resolve(
			array(
				'driver'       => 'lando',
				'lando_binary' => '/usr/bin/lando',
			),
			array( 'uploads_path' => '/nonexistent-dir-xyz/uploads' )
		);
	}
}
