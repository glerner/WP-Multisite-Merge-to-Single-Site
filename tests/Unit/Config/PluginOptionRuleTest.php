<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Unit\Config;

use MergeMultisite\Config\PluginOptionRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Per-plugin wp_options migration rules: include patterns,
 * per-option exclusions (exclude_option_keys), sub-key stripping,
 * and the credential-name heuristic used to FLAG migrated secrets
 * in the report (credentials migrate -- the destination plugin
 * needs them; they are never silently stripped).
 */
#[CoversClass( PluginOptionRule::class )]
final class PluginOptionRuleTest extends TestCase {

	public function testFromArrayParsesExcludeOptionKeys(): void {
		$rule = PluginOptionRule::fromArray(
			'akismet',
			array(
				'mode'                => 'include',
				'option_keys'         => array( 'akismet_*' ),
				'exclude_option_keys' => array( 'akismet_spam_count' ),
			)
		);

		self::assertSame( array( 'akismet_spam_count' ), $rule->excludeOptionKeys );
	}

	public function testExcludeOptionKeysWinOverIncludePatterns(): void {
		$rule = PluginOptionRule::fromArray(
			'akismet',
			array(
				'mode'                => 'include',
				'option_keys'         => array( 'akismet_*' ),
				'exclude_option_keys' => array( 'akismet_spam_count', 'akismet_lock_*' ),
			)
		);

		self::assertTrue( $rule->shouldMigrateOption( 'akismet_strictness' ) );
		self::assertFalse( $rule->shouldMigrateOption( 'akismet_spam_count' ) );
		// Wildcards work in the exclude list too.
		self::assertFalse( $rule->shouldMigrateOption( 'akismet_lock_run' ) );
	}

	/**
	 * Migrate-by-default: credential-named options DO migrate (the
	 * destination plugin needs e.g. its SMTP password to keep working,
	 * and anyone with DB access can already read them). They are
	 * flagged for the report via isSensitiveKeyName(), never blocked.
	 */
	public function testSensitiveOptionNamesStillMigrate(): void {
		$rule = PluginOptionRule::fromArray(
			'memberwing',
			array(
				'mode'        => 'include',
				'option_keys' => array( 'memberwing*', 'MemberWing*' ),
			)
		);

		self::assertTrue( $rule->shouldMigrateOption( 'memberwing_admin_options' ) );
		self::assertTrue( $rule->shouldMigrateOption( 'memberwing_smtp_password' ) );
		self::assertTrue( $rule->shouldMigrateOption( 'memberwing_license_key' ) );

		// ...but they are still flagged, for report visibility:
		self::assertTrue( PluginOptionRule::isSensitiveKeyName( 'memberwing_smtp_password' ) );
	}

	/**
	 * The credential pattern matches whole _-separated segments, so
	 * real secrets hit and lookalikes don't.
	 */
	public function testIsSensitiveKeyNameMatchesSegmentsNotSubstrings(): void {
		foreach ( array( 'smtp_password', 'clickbank_secret_key', 'mailchimp_api_key', 'app_consumer_secret', 'oauth_token', 'auth_salt' ) as $name ) {
			self::assertTrue( PluginOptionRule::isSensitiveKeyName( $name ), $name );
		}

		foreach ( array( 'memberwing_product_keyword', 'sage_part_numbers', 'smtp_use_authenticatioin', 'secretary_of_state', 'monkey_business', 'tokenizer_version' ) as $name ) {
			self::assertFalse( PluginOptionRule::isSensitiveKeyName( $name ), $name );
		}
	}

	public function testShouldStripSubkeyHonorsOnlyTheConfiguredList(): void {
		$rule = PluginOptionRule::fromArray(
			'memberwing',
			array(
				'mode'            => 'include_partial',
				'option_keys'     => array( 'MemberWing*' ),
				'exclude_subkeys' => array( 'protected_files_physical_addr' ),
			)
		);

		// Configured strip:
		self::assertTrue( $rule->shouldStripSubkey( 'protected_files_physical_addr' ) );
		// Credential-named keys are NOT auto-stripped -- they migrate
		// (and get flagged in the report):
		self::assertFalse( $rule->shouldStripSubkey( 'smtp_password' ) );
		self::assertFalse( $rule->shouldStripSubkey( 'memberwing_license_key' ) );
		// Ordinary settings stay:
		self::assertFalse( $rule->shouldStripSubkey( 'bronze_content_marker' ) );
		self::assertFalse( $rule->shouldStripSubkey( 'memberwing_product_keyword' ) );
	}

	/**
	 * Action Scheduler queue state and transient cache rows never
	 * migrate on any site, without needing a config entry -- the
	 * migrator's built-in list.
	 */
	public function testBuiltInExcludedOptionNamesNeverMigrate(): void {
		foreach ( array(
			'action_scheduler_lock_async-request-runner',
			'action_scheduler_hybrid_store_demarkation',
			'_transient_feed_1234',
			'_transient_timeout_feed_1234',
			'_site_transient_update_plugins',
		) as $name ) {
			self::assertTrue( PluginOptionRule::isBuiltInExcludedOptionName( $name ), $name );
		}

		foreach ( array( 'action_scheduler_version', 'siteurl', 'woocommerce_settings', '_transiented_nope' ) as $name ) {
			self::assertFalse( PluginOptionRule::isBuiltInExcludedOptionName( $name ), $name );
		}
	}
}
