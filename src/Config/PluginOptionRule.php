<?php

declare(strict_types=1);

namespace MergeMultisite\Config;

/**
 * One entry from `config/option-keys.php`: how a single plugin's
 * `wp_options` data should be treated during migration/audit.
 *
 * @package MergeMultisite
 */
final class PluginOptionRule {

	public const MODE_INCLUDE = 'include';
	public const MODE_INCLUDE_PARTIAL = 'include_partial';
	public const MODE_EXCLUDE = 'exclude';
	public const MODE_NEEDS_ADAPTER = 'needs_adapter';

	/**
	 * Option-name / sub-key segments that mark credential-bearing
	 * data: matched against whole `_`/`-`/`.`-separated segments so
	 * `smtp_password` and `mailchimp_api_key` hit (have 'password' or 'api') while
	 * `memberwing_product_keyword` or `sage_part_numbers` do not.
	 * isSensitiveKeyName() applies this for REPORTING -- credentials
	 * still migrate (the destination plugin needs e.g. its SMTP
	 * password to keep working, and anyone with DB access can
	 * already read them); the migrator flags them so no secret
	 * travels invisibly.
	 */
	public const SENSITIVE_NAME_PATTERN = '/(?:^|[_\-.])(password|passwd|pwd|secret|key|token|salt|nonce|credential|credentials|license)(?:[_\-.]|$)/i';

	/**
	 * Option-name patterns that never migrate on ANY site: runtime
	 * state that libraries/plugins create and recreate, not settings.
	 * Action Scheduler (bundled with WooCommerce and many add-ons)
	 * keeps queue locks and demarkation markers in wp_options;
	 * `_transient_*` / `_site_transient_*` rows are WordPress's own
	 * cache scratch space. The options migrator applies this list
	 * before any per-plugin rule, so no config entry is needed for
	 * these -- they are handled here for every website.
	 */
	public const BUILT_IN_EXCLUDED_OPTION_PATTERNS = array(
		'action_scheduler_lock_*',
		'*_demarkation',
		'_transient_*',
		'_transient_timeout_*',
		'_site_transient_*',
	);

	/**
	 * @param string      $pluginSlug        Plugin directory/slug, e.g. "wordfence".
	 * @param string      $mode              One of the MODE_* constants.
	 * @param string[]    $optionKeys        Option name patterns (leading or trailing "*" wildcard supported).
	 * @param string[]    $excludeSubkeys    For MODE_INCLUDE_PARTIAL, sub-option names to strip.
	 * @param string[]    $excludeOptionKeys Individual option names/patterns to drop even
	 *                                       under include/include_partial (transient state,
	 *                                       counters, one-off secrets).
	 * @param string|null $reason            Human-readable reason, shown in reports.
	 */
	public function __construct(
		public readonly string $pluginSlug,
		public readonly string $mode,
		public readonly array $optionKeys = array(),
		public readonly array $excludeSubkeys = array(),
		public readonly array $excludeOptionKeys = array(),
		public readonly ?string $reason = null,
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( string $pluginSlug, array $data ): self {
		return new self(
			pluginSlug: $pluginSlug,
			mode: (string) ( $data['mode'] ?? self::MODE_EXCLUDE ),
			optionKeys: array_map( 'strval', $data['option_keys'] ?? array() ),
			excludeSubkeys: array_map( 'strval', $data['exclude_subkeys'] ?? array() ),
			excludeOptionKeys: array_map( 'strval', $data['exclude_option_keys'] ?? array() ),
			reason: isset( $data['reason'] ) ? (string) $data['reason'] : null,
		);
	}

	/**
	 * Whether a given `wp_options.option_name` matches one of this
	 * rule's configured patterns.
	 */
	public function matchesOptionName( string $optionName ): bool {
		return self::matchesAnyPattern( $optionName, $this->optionKeys );
	}

	/**
	 * Whether an option is individually excluded under this rule
	 * (its `exclude_option_keys` list).
	 */
	public function isExcludedOptionName( string $optionName ): bool {
		return self::matchesAnyPattern( $optionName, $this->excludeOptionKeys );
	}

	/**
	 * For an option this rule governs (matched by `option_keys`),
	 * does it travel: not in `exclude_option_keys`. Called by the
	 * options migrator only after the rule's mode allowed the
	 * plugin's options at all (exclude/needs_adapter decide first).
	 * Options without a matching rule migrate by default -- this
	 * method is not reached for them.
	 */
	public function shouldMigrateOption( string $optionName ): bool {
		return $this->matchesOptionName( $optionName )
			&& ! $this->isExcludedOptionName( $optionName );
	}

	/**
	 * Whether a serialized sub-option key should be stripped inside
	 * an include_partial value: the configured `exclude_subkeys`
	 * list. Credential-looking keys are NOT auto-stripped (the
	 * destination plugin needs them); the migrator flags them via
	 * isSensitiveKeyName() for the report instead.
	 */
	public function shouldStripSubkey( string $subkey ): bool {
		return in_array( $subkey, $this->excludeSubkeys, true );
	}

	/**
	 * Credential-name heuristic for REPORTING (not blocking): any
	 * `_`/`-`/`.`-delimited segment that is a credential word
	 * (password, secret, key, token, salt, nonce, license,
	 * credential). Segment-exact so "keyword", "monkey", and
	 * "secretary" don't false-positive. The migrator lists
	 * sensitive-named options it migrated so secrets never travel
	 * invisibly.
	 */
	public static function isSensitiveKeyName( string $name ): bool {
		return preg_match( self::SENSITIVE_NAME_PATTERN, $name ) === 1;
	}

	/**
	 * Whether an option name matches the migrator's built-in
	 * never-migrate list (Action Scheduler queue state, transient
	 * cache rows). Applies before any per-plugin rule; these never
	 * migrate, on any site, without needing a config entry.
	 */
	public static function isBuiltInExcludedOptionName( string $optionName ): bool {
		return self::matchesAnyPattern( $optionName, self::BUILT_IN_EXCLUDED_OPTION_PATTERNS );
	}

	/**
	 * Wildcard match shared by the include/exclude lists and the
	 * built-in exclusions: `foo*` = prefix, `*foo` = suffix,
	 * `*foo*` = substring, otherwise exact.
	 *
	 * @param string[] $patterns
	 */
	private static function matchesAnyPattern( string $name, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( str_starts_with( $pattern, '*' ) && str_ends_with( $pattern, '*' ) ) {
				if ( str_contains( $name, substr( $pattern, 1, -1 ) ) ) {
					return true;
				}
			} elseif ( str_starts_with( $pattern, '*' ) ) {
				if ( str_ends_with( $name, substr( $pattern, 1 ) ) ) {
					return true;
				}
			} elseif ( str_ends_with( $pattern, '*' ) ) {
				if ( str_starts_with( $name, substr( $pattern, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $name === $pattern ) {
				return true;
			}
		}

		return false;
	}
}
