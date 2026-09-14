<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Flags site-wide `wp_options` settings that have DIFFERENT values on
 * different included subsites.
 *
 * The merged destination is a single site with a single options table,
 * so for any given site-wide option name only one value can survive.
 * Most such options (siteurl, blogname, etc.) are intentionally
 * per-site and get handled by the migration's own naming/rewriting
 * rules -- but plugin settings (e.g. an SEO plugin's title separator,
 * or a form plugin's spam-protection setting) are genuine conflicts:
 * someone's value will win, and that decision should be visible and
 * conscious rather than accidental.
 *
 * The output is deliberately "you have N divergences to review", not a
 * verdict -- ideally the merge plan should get this down to 0 or 1 by
 * pre-aligning the source sites or choosing a canonical value before
 * migrating.
 *
 * @package MergeMultisite
 */
final class DivergentSiteOptionCheck implements AuditCheckInterface {

	/**
	 * Cap on findings: each finding is one option name with differing
	 * values; beyond this the report would drown in e.g. per-plugin
	 * transient options, which are noise, not conflicts.
	 */
	private const MAX_FINDINGS = 50;

	/**
	 * Cap on how many sites are shown per distinct value.
	 */
	private const MAX_SITES_PER_VALUE = 5;

	/**
	 * Options that are EXPECTED to differ per site and are not
	 * conflicts: WordPress core per-site identity/settings that the
	 * migration itself rewrites or chooses (siteurl/home are rewritten
	 * to the destination domain, blogname/blogdescription/template/
	 * stylesheet are deliberately chosen per merged site,
	 * active_plugins is per-site activation state, admin_email and the
	 * mailserver* keys are network mail settings, page_on_front etc.
	 * are per-site page assignments), plus transient cache rows.
	 * Without this exclusion list the check would drown in noise and
	 * the real signal (plugin CONFIGURATION diverging across sites)
	 * would be invisible.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_OPTION_NAMES = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'template',
		'stylesheet',
		'template_root',
		'stylesheet_root',
		'active_plugins',
		'upload_path',
		'upload_url_path',
		'fileupload_url',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_protocol',
		'users_can_register',
		'use_balanceTags',
		'use_smilies',
		'default_category',
		'default_email_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'blog_charset',
		'gmt_offset',
		'comment_moderation',
		'comment_max_links',
		'moderation_notify',
		'comments_notify',
		'ping_sites',
		'permalink_structure',
		'category_base',
		'tag_base',
		'db_version',
		'initial_db_version',
		'timezone_string',
		'date_format',
		'time_format',
		'start_of_week',
		'page_on_front',
		'page_for_posts',
		'show_on_front',
		'blog_public',
		'WPLANG',
		'site_logo',
	);

	public function name(): string {
		return 'divergent-site-options';
	}

	public function description(): string {
		return 'Site-wide options whose value differs across subsites; the merged site keeps ONE value per option -- pick the canonical one. Expected per-site values (siteurl, blogname, ...) are already filtered out.';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		// Per-site option rows, keyed by option_name.
		$valuesByOption = array();
		foreach ( $sites as $site ) {
			$optionsTable = $source->siteTable( 'options', $site->blogId );
			$rows = $source->fetchAll(
				"SELECT option_name, option_value FROM {$optionsTable} WHERE autoload = 'yes'"
			);

			foreach ( $rows as $row ) {
				$valuesByOption[ (string) $row['option_name'] ][ $site->blogId ] = (string) $row['option_value'];
			}
		}

		// Collect divergent options first so the report can both show
		// them (up to MAX_FINDINGS) and say how many were left out.
		/** @var array<string, array<string, int[]>> $divergent option name => raw value => blog_ids */
		$divergent = array();

		foreach ( $valuesByOption as $optionName => $sitesByValue ) {
			if ( in_array( $optionName, self::EXCLUDED_OPTION_NAMES, true ) ) {
				continue;
			}

			if ( str_starts_with( $optionName, '_transient' ) || str_starts_with( $optionName, '_site_transient' ) ) {
				continue;
			}

			// Widget instances and sidebar assignments are inherently
			// per-site (and the MenuMigrator already namespaces them as
			// orphaned data for manual placement) -- comparing them
			// across sites would produce noise, not conflicts.
			if ( str_starts_with( $optionName, 'widget_' ) || $optionName === 'sidebars_widgets' ) {
				continue;
			}

			// Ephemeral state, not configuration: cache plugins
			// (supercache etc.) keep timestamps/stats that differ per
			// site and are never worth canonicalizing, and plugin
			// "version"/"last X"/"start time" stamps are bookkeeping
			// that will be superseded on the destination anyway.
			if ( stripos( $optionName, 'cache' ) !== false ) {
				continue;
			}

			foreach ( array( '_version', '_last', '_start', '_counter', '_stats', '_gc_time', '_diagnostics' ) as $ephemeralSuffix ) {
				if ( str_ends_with( $optionName, $ephemeralSuffix ) ) {
					continue 2;
				}
			}

			// Serialized values are deterministic: identical settings
			// produce identical strings, so grouping by raw value is a
			// reliable equality test without unserializing. Note the
			// explicit (string) cast on the array key: numeric-looking
			// option values would otherwise become int keys in PHP,
			// breaking the string handling below.
			$valueGroups = array();
			foreach ( $sitesByValue as $blogId => $value ) {
				$valueGroups[ (string) $value ][] = $blogId;
			}

			if ( count( $valueGroups ) < 2 ) {
				continue; // Same value everywhere -- not a conflict.
			}

			$divergent[ $optionName ] = $valueGroups;
		}

		ksort( $divergent );

		$findings = array();
		foreach ( array_slice( $divergent, 0, self::MAX_FINDINGS, true ) as $optionName => $valueGroups ) {
			$findings[] = AuditFinding::warning(
				$this->name(),
				$this->optionMessage( $optionName, $valueGroups ),
				array(
					'option_name' => $optionName,
					'groups' => array_map(
						static fn ( $value, array $blogIds ): array => array(
							'value' => (string) $value,
							'sites' => $blogIds,
						),
						array_keys( $valueGroups ),
						array_values( $valueGroups )
					),
				)
			);
		}

		$remaining = count( $divergent ) - self::MAX_FINDINGS;
		if ( $remaining > 0 ) {
			$findings[] = AuditFinding::info(
				$this->name() . '.truncated',
				sprintf( '%d more divergent option name(s) not shown: %s', $remaining, implode( ', ', array_slice( array_keys( $divergent ), self::MAX_FINDINGS ) ) )
			);
		}

		return $findings;
	}

	/**
	 * Few distinct values render inline ("name": "a", "b"); many get
	 * one line per value with the sites holding it.
	 *
	 * @param array<string, int[]> $valueGroups
	 */
	private function optionMessage( string $optionName, array $valueGroups ): string {
		// Array keys are ints when a value looks numeric ("1"), so cast
		// every key back to string before string handling.
		$quotedValues = array();
		foreach ( $valueGroups as $value => $blogIds ) {
			$quotedValues[ (string) $value ] = $blogIds;
		}
		ksort( $quotedValues );
		$valueGroups = $quotedValues;

		if ( count( $valueGroups ) <= 3 ) {
			$quotedValues = array_map(
				static fn ( string $v ): string => '"' . self::truncate( $v ) . '"',
				array_keys( $valueGroups )
			);

			return sprintf( '"%s": %s', $optionName, implode( ', ', $quotedValues ) );
		}

		$lines = array( sprintf( '"%s":', $optionName ) );
		foreach ( $valueGroups as $value => $blogIds ) {
			sort( $blogIds );
			$siteList = implode( ', ', array_slice( $blogIds, 0, self::MAX_SITES_PER_VALUE ) );
			if ( count( $blogIds ) > self::MAX_SITES_PER_VALUE ) {
				$siteList .= sprintf( ' (+%d more)', count( $blogIds ) - self::MAX_SITES_PER_VALUE );
			}
			$lines[] = sprintf( '  sites %s: "%s"', $siteList, self::truncate( (string) $value ) );
		}

		return implode( "\n", $lines );
	}

	private static function truncate( string $value ): string {
		return strlen( $value ) > 80 ? substr( $value, 0, 77 ) . '...' : $value;
	}
}
