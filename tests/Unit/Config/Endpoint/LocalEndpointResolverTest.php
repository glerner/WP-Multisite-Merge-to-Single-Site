<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config\Endpoint;

use MergeMultisite\Config\ConfigException;
use MergeMultisite\Config\Endpoint\LocalEndpointResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( LocalEndpointResolver::class )]
final class LocalEndpointResolverTest extends TestCase {

	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/merge-multisite-local-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tempDir . '/run/site-id-1/mysql', 0775, true );
		file_put_contents(
			$this->tempDir . '/sites.json',
			(string) json_encode(
				array(
					'site-id-1' => array(
						'id'       => 'site-id-1',
						'name'     => 'mysite',
						'path'     => '/home/user/Local Sites/mysite',
						'services' => array(
							'mysql' => array( 'ports' => array( 'MYSQL' => array( 10006 ) ) ),
						),
					),
				)
			)
		);
	}

	protected function tearDown(): void {
		$socketFiles = glob( $this->tempDir . '/run/site-id-1/mysql/*' );
		foreach ( $socketFiles === false ? array() : $socketFiles as $file ) {
			unlink( $file );
		}
		unlink( $this->tempDir . '/sites.json' );
		rmdir( $this->tempDir . '/run/site-id-1/mysql' );
		rmdir( $this->tempDir . '/run/site-id-1' );
		rmdir( $this->tempDir . '/run' );
		rmdir( $this->tempDir );
	}

	/**
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	private function spec( array $extra = array() ): array {
		return array_merge(
			array(
				'driver'     => 'local',
				'site'       => 'mysite',
				'sites_json' => $this->tempDir . '/sites.json',
			),
			$extra
		);
	}

	public function testPrefersSocketWhileSiteIsRunning(): void {
		$socketPath = $this->tempDir . '/run/site-id-1/mysql/mysqld.sock';
		touch( $socketPath );

		$endpoint = ( new LocalEndpointResolver() )->resolve( $this->spec(), array() );

		self::assertSame( $socketPath, $endpoint->socket );
	}

	public function testFallsBackToPublishedPortWhenNoSocket(): void {
		$endpoint = ( new LocalEndpointResolver() )->resolve( $this->spec(), array() );

		self::assertSame( '127.0.0.1', $endpoint->host );
		self::assertSame( 10006, $endpoint->port );
		self::assertNull( $endpoint->socket );
	}

	public function testFindsSiteByPath(): void {
		$endpoint = ( new LocalEndpointResolver() )->resolve(
			array(
				'driver'     => 'local',
				'site_path'  => '/home/user/Local Sites/mysite',
				'sites_json' => $this->tempDir . '/sites.json',
			),
			array()
		);

		self::assertSame( 10006, $endpoint->port );
	}

	public function testThrowsWhenSiteNotFound(): void {
		$this->expectException( ConfigException::class );
		$this->expectExceptionMessage( 'Sites present' );

		( new LocalEndpointResolver() )->resolve(
			$this->spec( array( 'site' => 'no-such-site' ) ),
			array()
		);
	}

	public function testThrowsWhenNeitherSiteNorSitePathSet(): void {
		$this->expectException( ConfigException::class );

		( new LocalEndpointResolver() )->resolve(
			array(
				'driver'     => 'local',
				'sites_json' => $this->tempDir . '/sites.json',
			),
			array()
		);
	}

	public function testThrowsWhenExplicitSitesJsonIsMissing(): void {
		$this->expectException( ConfigException::class );

		( new LocalEndpointResolver() )->resolve(
			$this->spec( array( 'sites_json' => $this->tempDir . '/nope.json' ) ),
			array()
		);
	}
}
