<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\SerializedDataRewriter;

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
	 * Replacement for each site's own home URL when comparing option
	 * values, so two otherwise-identical settings that merely embed
	 * the site's domain compare equal. Chosen to be unmistakable in
	 * any reported value that still contains it.
	 */
	private const HOME_PLACEHOLDER = '__MERGE_SITE_HOME__';

	/**
	 * Cap on findings: each finding is one option name with differing
	 * values; beyond this the report would drown in e.g. per-plugin
	 * transient options, which are noise, not conflicts. The complete
	 * map still travels in the '.truncated' finding's context and is
	 * written to the "divergent-options" tab of integrity-*.xlsx
	 * (CSV fallback without ext-zip) -- nothing is dropped, only the
	 * Markdown display is capped.
	 */
	private const MAX_FINDINGS = 50;

	/**
	 * Cap on how much of a single option VALUE is shown in the
	 * Markdown report (serialized option blobs can be tens of KB).
	 * Full values are always in the JSON context and the CSV detail.
	 */
	private const MAX_VALUE_DISPLAY = 1000;

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

	/**
	 * Rewrites serialized values with correct string-length prefixes
	 * when normalizing embedded home URLs (CR-403).
	 *
	 * @var SerializedDataRewriter
	 */
	private readonly SerializedDataRewriter $rewriter;

	public function __construct( ?SerializedDataRewriter $rewriter = null ) {
		$this->rewriter = $rewriter ?? new SerializedDataRewriter();
	}

	public function name(): string {
		return 'divergent-site-options';
	}

	public function description(): string {
		return 'Autoloaded site-wide options (autoload = \'yes\') whose value differs across subsites; the merged site keeps ONE value per option -- pick the canonical one. Expected per-site values (siteurl, blogname, ...) are already filtered out, as are configured divergent-options.php exclusions and differences that only embed each site\'s own home URL.';
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

		// Each site's canonical home URL (falling back to siteurl),
		// used to normalize embedded URLs before comparing option
		// values below.
		$homeUrlBySite = array();
		foreach ( $sites as $site ) {
			$home = $valuesByOption['home'][ $site->blogId ] ?? $valuesByOption['siteurl'][ $site->blogId ] ?? '';
			if ( $home !== '' ) {
				$homeUrlBySite[ $site->blogId ] = $home;
			}
		}

		$excludedOptionNames = array_merge( self::EXCLUDED_OPTION_NAMES, $config->divergentOptionExclusions );

		// Collect divergent options first so the report can both show
		// them (up to MAX_FINDINGS) and say how many were left out.
		/** @var array<string, array<string, int[]>> $divergent option name => raw value => blog_ids */
		$divergent = array();

		foreach ( $valuesByOption as $optionName => $sitesByValue ) {
			if ( in_array( $optionName, $excludedOptionNames, true ) ) {
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
			// produce identical strings, so grouping by value is a
			// reliable equality test without unserializing -- after
			// normalizing each site's own home URL to a placeholder
			// first. A plugin setting that is byte-identical except
			// for the embedded domain is migration-rewritten noise,
			// not a configuration conflict.
			//
			// PHP casts numeric-looking string keys to ints
			// automatically ("1" becomes key 1), so every use of these
			// groups below casts keys back to string before rendering
			// (the collapse to $displayGroups and optionMessage()).
			$valueGroups = array();
			foreach ( $sitesByValue as $blogId => $value ) {
				$normalized = $this->normalizeOptionHomeUrl( $value, $homeUrlBySite[ $blogId ] ?? '' );
				$valueGroups[ $normalized ][ $blogId ] = $value;
			}

			if ( count( $valueGroups ) < 2 ) {
				continue; // Same value everywhere (or same once URLs are normalized) -- not a conflict.
			}

			// Collapse each normalized group back to one raw value for
			// display (the first site's actual string) so the finding
			// shows what really differs, not the placeholder form.
			$displayGroups = array();
			foreach ( $valueGroups as $normalized => $siteValues ) {
				$displayGroups[ (string) reset( $siteValues ) ] = array_map( 'intval', array_keys( $siteValues ) );
			}

			$divergent[ $optionName ] = $displayGroups;
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
				sprintf(
					'%d more divergent option name(s) not shown. The complete list -- every option, every distinct value, and the sites holding each -- is in the "divergent-options" tab of var/reports/integrity-*.xlsx (or the same-named .csv when ext-zip is missing), and under this finding\'s "divergent_options" context in the JSON report.',
					$remaining
				),
				array( 'divergent_options' => $divergent )
			);
		}

		return $findings;
	}

	/**
	 * Replace every occurrence of a site's home URL with a placeholder
	 * so two otherwise-identical option values that merely embed their
	 * own domain compare equal. Serialized values go through
	 * SerializedDataRewriter so PHP string-length prefixes stay
	 * correct after the substitution; plain strings are replaced
	 * directly. Values that don't contain the URL (or have no URL
	 * known for the site) are returned unchanged.
	 */
	private function normalizeOptionHomeUrl( string $value, string $homeUrl ): string {
		if ( $homeUrl === '' || ! str_contains( $value, $homeUrl ) ) {
			return $value;
		}

		if ( $this->rewriter->isSerialized( $value ) ) {
			return $this->rewriter->rewriteStrings(
				$value,
				static fn ( string $s ): string => str_replace( $homeUrl, self::HOME_PLACEHOLDER, $s )
			);
		}

		return str_replace( $homeUrl, self::HOME_PLACEHOLDER, $value );
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

	/**
	 * Values display in full up to MAX_VALUE_DISPLAY characters; longer
	 * blobs are truncated with an ellipsis (full text is in JSON/CSV).
	 */
	private static function truncate( string $value ): string {
		return strlen( $value ) > self::MAX_VALUE_DISPLAY ? substr( $value, 0, self::MAX_VALUE_DISPLAY - 3 ) . '...' : $value;
	}
}
