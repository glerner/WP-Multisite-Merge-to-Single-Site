<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Audit\Checks\PluginRepoCheck;
use MergeMultisite\Migration\PluginInventory;
use MergeMultisite\Migration\Site;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * PluginRepoCheck against the SQLite fixture + a tmp plugins dir, with
 * an injected directory lookup so no real HTTP happens.
 *
 * @package MergeMultisite
 */
#[CoversClass( PluginRepoCheck::class )]
final class PluginRepoCheckTest extends SqliteTestCase {

	private string $wpContentDir;

	protected function setUp(): void {
		parent::setUp();

		$this->wpContentDir = sys_get_temp_dir() . '/merge-multisite-plugin-repo-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->wpContentDir . '/plugins/repo-plugin', 0775, true );
		mkdir( $this->wpContentDir . '/plugins/mystery-plugin', 0775, true );
		mkdir( $this->wpContentDir . '/mu-plugins', 0775, true );

		file_put_contents(
			$this->wpContentDir . '/plugins/repo-plugin/repo-plugin.php',
			"<?php\n/**\n * Plugin Name: Repo Plugin\n */\n"
		);
		file_put_contents(
			$this->wpContentDir . '/plugins/mystery-plugin/mystery-plugin.php',
			"<?php\n/**\n * Plugin Name: Mystery Plugin\n */\n"
		);
		file_put_contents(
			$this->wpContentDir . '/mu-plugins/custom-loader.php',
			"<?php\n// mu-plugin, custom code -- never a wp.org slug\n"
		);
	}

	protected function tearDown(): void {
		$this->removeDirectory( $this->wpContentDir );
		parent::tearDown();
	}

	public function testUnknownPluginIsFlagged(): void {
		$check = $this->check(
			static fn ( array $slugs ): array => array( 'repo-plugin' )
		);

		$findings = $check->run( ...$this->fixture( array() ) );

		self::assertCount( 1, $findings );
		self::assertSame( 'plugin-repo.unknown', $findings[0]->checkName );
		self::assertSame( 'warning', $findings[0]->severity );
		self::assertSame( array( 'mystery-plugin' ), $findings[0]->context['plugins'] );
		self::assertStringContainsString( 'mystery-plugin', $findings[0]->message );
	}

	public function testAllKnownProducesNoFindings(): void {
		$check = $this->check(
			static fn ( array $slugs ): array => $slugs
		);

		$findings = $check->run( ...$this->fixture( array() ) );

		self::assertSame( array(), $findings );
	}

	public function testAllowlistedPluginStaysSilent(): void {
		$checked = array();
		$check   = $this->check(
			function ( array $slugs ) use ( &$checked ): array {
				$checked = $slugs;
				return $slugs;
			}
		);

		$findings = $check->run( ...$this->fixture( array( 'mystery-plugin' ) ) );

		self::assertSame( array(), $findings );
		// The allowlisted slug and the mu-plugin were never looked up.
		self::assertSame( array( 'repo-plugin' ), $checked );
	}

	public function testLookupFailureSkipsTheCheck(): void {
		$check = $this->check(
			static fn ( array $slugs ): ?array => null
		);

		$findings = $check->run( ...$this->fixture( array() ) );

		self::assertCount( 1, $findings );
		self::assertSame( 'plugin-repo.skipped', $findings[0]->checkName );
		self::assertSame( 'info', $findings[0]->severity );
	}

	public function testDbActiveButMissingFromDiskIsChecked(): void {
		$checked = array();
		$check   = $this->check(
			function ( array $slugs ) use ( &$checked ): array {
				$checked = $slugs;
				return array( 'repo-plugin' );
			}
		);

		$findings = $check->run( ...$this->fixture( array(), true ) );

		self::assertContains( 'db-only-plugin', $checked );
		self::assertCount( 1, $findings );
		self::assertContains( 'db-only-plugin', $findings[0]->context['plugins'] );
		self::assertContains( 'mystery-plugin', $findings[0]->context['plugins'] );
		// The DB-only plugin reports where it is switched on.
		self::assertSame( array( 1 ), $findings[0]->context['active_on']['db-only-plugin'] );
	}

	/**
	 * Builds the check with a tmp-dir inventory and a fake directory
	 * lookup (no HTTP).
	 *
	 * @param \Closure $lookup (string[] $slugs): ?string[] -- found subset or null.
	 */
	private function check( \Closure $lookup ): PluginRepoCheck {
		return new PluginRepoCheck(
			inventory: new PluginInventory(
				$this->wpContentDir . '/plugins',
				$this->wpContentDir . '/mu-plugins'
			),
			directoryLookup: $lookup,
		);
	}

	/**
	 * The run() argument triple: sqlite source with an optional
	 * DB-active plugin, config carrying the premium allowlist, and one
	 * included site.
	 *
	 * @param string[] $allowlist
	 *
	 * @return array{0: \MergeMultisite\Db\Connection, 1: \MergeMultisite\Config\MergeConfig, 2: Site[]}
	 */
	private function fixture( array $allowlist, bool $dbOnlyPlugin = false ): array {
		$source = $this->sqliteConnection( 'wp_', array( 1 ) );

		if ( $dbOnlyPlugin ) {
			WpTestSchema::insertOption(
				$source->pdo(),
				$source->siteTable( 'options', 1 ),
				array(
					'option_name'  => 'active_plugins',
					'option_value' => serialize( array( 'db-only-plugin/db-only-plugin.php' ) ),
				)
			);
		}

		return array(
			$source,
			$this->testConfig(
				overrides: array(
					'plugin_roles' => array( 'premium_plugins' => $allowlist ),
				)
			),
			array( new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ) ),
		);
	}
}
