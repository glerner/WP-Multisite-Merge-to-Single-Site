<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Integration;

use MergeMultisite\Config\PluginOptionRule;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\IdMap;
use MergeMultisite\Migration\MigrationTable;
use MergeMultisite\Migration\OptionsMigrator;
use MergeMultisite\Migration\Site;
use MergeMultisite\Support\Logger;
use MergeMultisite\Tests\Support\SqliteTestCase;
use MergeMultisite\Tests\Support\WpTestSchema;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * OptionsMigrator end-to-end on SQLite: migrate-by-default, the
 * built-in never-migrate list, option-keys.php rules (exclude,
 * include_partial sub-key stripping, exclude_option_keys,
 * needs_adapter), cross-site conflict resolution (main_site wins,
 * otherwise lowest blog_id; identical values merged), known-option ID
 * remapping (site_icon/default_category), sensitive-name flagging,
 * destination-row upsert, dry-run writing nothing, and resume
 * idempotency.
 *
 * @package MergeMultisite
 */
#[CoversClass( OptionsMigrator::class )]
final class OptionsMigratorTest extends SqliteTestCase {

	private Connection $source;

	private Connection $destination;

	private MigrationTable $mapTable;

	private IdMap $idMap;

	protected function setUp(): void {
		parent::setUp();

		$this->source = $this->sqliteConnection( 'wp_', array( 1, 7 ) );
		$this->destination = $this->sqliteConnection( 'wp_', array( 1 ) );
		$this->mapTable = new MigrationTable();
		$this->mapTable->ensure( $this->destination );

		$this->idMap = new IdMap();
		// Attachment 501 (site 1) -> 901, category term 5 (site 7) is
		// NOT mapped (the unmapped-kept case).
		$this->idMap->set( 'attachment', 1, 501, 901 );
		// Front-page posts for site 1 (page_on_front 101, page_for_posts 102).
		$this->idMap->set( 'post', 1, 101, 201 );
		$this->idMap->set( 'post', 1, 102, 202 );

		$this->seedDestinationState();
	}

	/**
	 * The destination's own install state (as a fresh single site has):
	 * identity options the migrator must never touch, plus an existing
	 * shared_option row the migration should overwrite.
	 */
	private function seedDestinationState(): void {
		$optionsTable = $this->destination->siteTable( 'options', 1 );
		WpTestSchema::insertOption(
			$this->destination->pdo(),
			$optionsTable,
			array(
			'option_name' => 'blogname',
			'option_value' => 'Dest Site',
			)
		);
		WpTestSchema::insertOption(
			$this->destination->pdo(),
			$optionsTable,
			array(
			'option_name' => 'active_plugins',
			'option_value' => serialize( array( 'dest-plugin/active.php' ) ),
			)
		);
		WpTestSchema::insertOption(
			$this->destination->pdo(),
			$optionsTable,
			array(
			'option_name' => 'shared_option',
			'option_value' => 'dest-value',
			)
		);
	}

	public function testMigrateByDefaultWithExclusionsRulesAndConflicts(): void {
		$this->seedSiteOneOptions();
		$this->seedSiteSevenOptions();

		$report = $this->runMigrate( dryRun: false, mainSite: null );

		self::assertSame( 9, $report['options_migrated'] );
		self::assertSame( 11, $report['options_excluded'] );
		self::assertSame( 2, $report['conflicts_resolved'] );
		self::assertSame( 1, $report['conflicts_identical'] );
		self::assertSame( 1, $report['option_ids_unmapped'] );
		self::assertSame( 1, $report['destination_overwrites'] );
		self::assertCount( 1, $report['needs_adapter'] );
		self::assertCount( 1, $report['sensitive_migrated'] );
		self::assertCount( 1, $report['sensitive_subkeys'] );
		self::assertCount( 2, $report['divergences'] );
		self::assertSame( array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' ), $report['active_plugins_union'] );
		self::assertSame( 8, $report['by_site'][1]['options'] );
		self::assertSame( 1, $report['by_site'][7]['options'] );

		// Front-page options never migrate, but their values are
		// captured with the destination post IDs for the to-do list.
		self::assertSame(
			array(
				'blog_id' => 1,
				'show_on_front' => 'page',
				'page_on_front' => 101,
				'page_on_front_destination' => 201,
				'page_for_posts' => 102,
				'page_for_posts_destination' => 202,
			),
			$report['front_page']
		);

		$options = $this->destinationOptions();

		// Migrate-by-default + conflict winner (lowest blog_id = 1).
		self::assertSame( 'site1 value', $options['my_plugin_setting'] );
		self::assertSame( 'value-a', $options['conflicting_option'] );
		self::assertSame( 'same', $options['shared_option'] );

		// Known core option IDs remapped; unmapped kept + counted.
		self::assertSame( '901', $options['site_icon'] );
		self::assertSame( '999', $options['default_category'] );

		// include_partial stripped the transient sub-keys.
		$wordfence = unserialize( $options['wordfence_config'] );
		self::assertTrue( $wordfence['enabled'] );
		self::assertArrayNotHasKey( 'wordfence_blockedIPLog', $wordfence );
		self::assertArrayNotHasKey( 'wordfence_loginLockedOut', $wordfence );

		// Rule exclusions never migrated.
		self::assertArrayNotHasKey( 'qm_foo', $options );
		self::assertArrayNotHasKey( 'akismet_spam_count', $options );
		self::assertArrayNotHasKey( 'custom_table_setting', $options );

		// Built-in exclusions: the destination's own values survive.
		self::assertSame( 'Dest Site', $options['blogname'] );
		self::assertSame( 'dest-plugin/active.php', unserialize( $options['active_plugins'] )[0] );

		// Sensitive-named options migrate (flagged, not blocked).
		self::assertSame( 'secret1', $options['smtp_password'] );
		$serializedSensitive = unserialize( $options['serialized_sensitive'] );
		self::assertSame( 'k', $serializedSensitive['mailchimp_api_key'] );
	}

	public function testMainSiteWinsConflicts(): void {
		$this->seedSiteOneOptions();
		$this->seedSiteSevenOptions();

		$report = $this->runMigrate( dryRun: false, mainSite: 7 );

		$options = $this->destinationOptions();

		self::assertSame( 'site7 value', $options['my_plugin_setting'] );
		self::assertSame( 'value-b', $options['conflicting_option'] );
		self::assertSame( 2, $report['conflicts_resolved'] );
		// Divergences are recorded in the pass-B name order (sorted).
		self::assertSame( array( 'conflicting_option', 'my_plugin_setting' ), array_column( $report['divergences'], 'option' ) );
	}

	public function testDryRunWritesNothing(): void {
		$this->seedSiteOneOptions();
		$this->seedSiteSevenOptions();

		$report = $this->runMigrate( dryRun: true, mainSite: null );

		self::assertSame( 9, $report['options_migrated'] );
		self::assertSame( 2, $report['conflicts_resolved'] );
		// Only the three options seeded on the destination remain.
		self::assertSame( 3, (int) $this->destination->fetchScalar( "SELECT COUNT(*) FROM {$this->destination->siteTable( 'options', 1 )}" ) );
	}

	public function testRerunSkipsAlreadyMigratedOptions(): void {
		$this->seedSiteOneOptions();
		$this->seedSiteSevenOptions();
		$this->runMigrate( dryRun: false, mainSite: null );

		$second = $this->runMigrate( dryRun: false, mainSite: null );

		self::assertSame( 9, $second['options_skipped'] );
		self::assertSame( 0, $second['options_migrated'] );
		self::assertSame( 'site1 value', $this->destinationOptions()['my_plugin_setting'] );
	}

	/**
	 * Site 1 options exercising every path: built-in exclusions, rule
	 * exclusions, include_partial, sensitive names, conflict sides.
	 */
	private function seedSiteOneOptions(): void {
		$optionsTable = $this->source->siteTable( 'options', 1 );

		foreach (
			array(
				'blogname' => 'Old Name', // built-in exclusion (destination identity).
				'siteurl' => 'https://site1.example.test', // built-in exclusion.
				'wp_user_roles' => serialize( array( 'administrator' => array() ) ), // Phase 3 owns roles.
				'widget_text' => 'a:1:{i:1;s:5:"hello";}', // widgets phase owns widget_*.
				'_transient_foo' => 'x', // runtime/transient.
				'active_plugins' => serialize( array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' ) ),
				'qm_foo' => 'x', // excluded by the query-monitor rule.
				'akismet_spam_count' => '5', // exclude_option_keys under akismet_*.
				'akismet_result' => 'true',
				'wordfence_config' => serialize(
					array(
						'enabled' => true,
						'wordfence_blockedIPLog' => '1.2.3.4',
						'wordfence_loginLockedOut' => 'user1',
					)
				),
				'custom_table_setting' => 'x', // needs_adapter rule.
				'site_icon' => '501', // attachment IdMap -> 901.
				'smtp_password' => 'secret1', // sensitive name, migrates + flagged.
				'serialized_sensitive' => serialize(
					array(
					'mailchimp_api_key' => 'k',
					'plain' => 'v',
					)
				),
				'shared_option' => 'same',
				'conflicting_option' => 'value-a',
				'my_plugin_setting' => 'site1 value',
				'show_on_front' => 'page', // excluded, captured for the to-do list.
				'page_on_front' => '101',
				'page_for_posts' => '102',
			) as $name => $value
		) {
			WpTestSchema::insertOption(
				$this->source->pdo(),
				$optionsTable,
				array(
				'option_name' => $name,
				'option_value' => $value,
				)
			);
		}
	}

	/**
	 * Site 7: the other side of the conflicts plus an unmapped
	 * default_category.
	 */
	private function seedSiteSevenOptions(): void {
		$optionsTable = $this->source->siteTable( 'options', 7 );

		foreach (
			array(
				'shared_option' => 'same',
				'conflicting_option' => 'value-b',
				'my_plugin_setting' => 'site7 value',
				'default_category' => '999', // term 999 unmapped -> kept + counted.
			) as $name => $value
		) {
			WpTestSchema::insertOption(
				$this->source->pdo(),
				$optionsTable,
				array(
				'option_name' => $name,
				'option_value' => $value,
				)
			);
		}
	}

	private function optionsRules(): array {
		return array(
			'akismet' => new PluginOptionRule(
				pluginSlug: 'akismet',
				mode: PluginOptionRule::MODE_INCLUDE,
				optionKeys: array( 'akismet_*' ),
				excludeOptionKeys: array( 'akismet_spam_count' )
			),
			'wordfence' => new PluginOptionRule(
				pluginSlug: 'wordfence',
				mode: PluginOptionRule::MODE_INCLUDE_PARTIAL,
				optionKeys: array( 'wordfence*' ),
				excludeSubkeys: array( 'wordfence_blockedIPLog', 'wordfence_loginLockedOut' )
			),
			'query-monitor' => new PluginOptionRule(
				pluginSlug: 'query-monitor',
				mode: PluginOptionRule::MODE_EXCLUDE,
				optionKeys: array( 'qm_*' ),
				reason: 'Dev-only tool.'
			),
			'my-custom-plugin' => new PluginOptionRule(
				pluginSlug: 'my-custom-plugin',
				mode: PluginOptionRule::MODE_NEEDS_ADAPTER,
				optionKeys: array( 'custom_table_*' )
			),
		);
	}

	/**
	 * @return array{
	 *     options_migrated: int, options_skipped: int, options_excluded: int,
	 *     option_ids_unmapped: int, destination_overwrites: int,
	 *     conflicts_resolved: int, conflicts_identical: int,
	 *     excluded_lines: string[], needs_adapter: string[],
	 *     sensitive_migrated: string[], sensitive_subkeys: string[],
	 *     active_plugins_union: string[],
	 *     divergences: array<int, array{option: string, winner_blog_id: int, sites: int[]}>,
	 *     warnings: string[], by_site: array<int, array{options:int}>
	 * }
	 */
	private function runMigrate( bool $dryRun, ?int $mainSite ): array {
		$idMap = new IdMap();
		$this->mapTable->prewarm( $this->destination, $idMap );
		foreach ( array( 'attachment', 'post' ) as $type ) {
			foreach ( $this->idMap->entries( $type ) as $key => $value ) {
				[ $siteId, $oldId ] = explode( ':', $key, 2 );
				$idMap->set( $type, (int) $siteId, is_numeric( $oldId ) ? (int) $oldId : $oldId, $value );
			}
		}

		return ( new OptionsMigrator() )->migrate(
			$this->source,
			$this->destination,
			$this->testConfig(
				overrides: array(
					'plugin_option_rules' => $this->optionsRules(),
					'main_site' => $mainSite,
				)
			),
			array(
				new Site( blogId: 1, domain: 'main.example.test', path: '/', title: 'Main', deleted: false, included: true ),
				new Site( blogId: 7, domain: 'seven.example.test', path: '/', title: 'Seven', deleted: false, included: true ),
			),
			$idMap,
			$this->mapTable,
			$dryRun,
			new Logger( 'error' )
		);
	}

	/**
	 * The destination options as option_name => option_value.
	 *
	 * @return array<string, string>
	 */
	private function destinationOptions(): array {
		$rows = $this->destination->fetchAll(
			"SELECT option_name, option_value FROM {$this->destination->siteTable( 'options', 1 )}"
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row['option_name'] ] = (string) $row['option_value'];
		}

		return $map;
	}
}
