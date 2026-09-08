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

		$findings = array();
		foreach ( $valuesByOption as $optionName => $sitesByValue ) {
			if ( count( $findings ) >= self::MAX_FINDINGS ) {
				break;
			}

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

			$summary = array();
			foreach ( $valueGroups as $value => $blogIds ) {
				// PHP converts numeric-looking string keys to int
				// automatically, so re-cast here to keep string
				// handling (strlen/truncation) type-safe.
				$value = (string) $value;

				sort( $blogIds );
				$sitesShown = array_slice( $blogIds, 0, self::MAX_SITES_PER_VALUE );
				$siteList = implode( ', ', array_map( static fn ( int $id ): string => 'site ' . $id, $sitesShown ) );
				if ( count( $blogIds ) > self::MAX_SITES_PER_VALUE ) {
					$siteList .= sprintf( ' (and %d more)', count( $blogIds ) - self::MAX_SITES_PER_VALUE );
				}

				$displayValue = strlen( $value ) > 80 ? substr( $value, 0, 77 ) . '...' : $value;
				$summary[] = sprintf( 'value "%s" on %s', $displayValue, $siteList );
			}

			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'Site-wide option "%s" has %d different value(s) across subsites (%s) -- the merged site has one options table, so a canonical value must be chosen; review before/after migration.',
					$optionName,
					count( $valueGroups ),
					implode( '; ', $summary )
				),
				array(
					'option_name' => $optionName,
					'groups' => array_map(
						static fn ( string $value, array $blogIds ): array => array(
							'value' => $value,
							'sites' => $blogIds,
						),
						array_keys( $valueGroups ),
						array_values( $valueGroups )
					),
				)
			);
		}

		return $findings;
	}
}
