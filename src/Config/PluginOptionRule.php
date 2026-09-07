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
	 * @param string      $pluginSlug     Plugin directory/slug, e.g. "wordfence".
	 * @param string      $mode           One of the MODE_* constants.
	 * @param string[]    $optionKeys     Option name patterns (trailing "*" wildcard supported).
	 * @param string[]    $excludeSubkeys For MODE_INCLUDE_PARTIAL, sub-option names to strip.
	 * @param string|null $reason         Human-readable reason, shown in reports.
	 */
	public function __construct(
		public readonly string $pluginSlug,
		public readonly string $mode,
		public readonly array $optionKeys = array(),
		public readonly array $excludeSubkeys = array(),
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
			reason: isset( $data['reason'] ) ? (string) $data['reason'] : null,
		);
	}

	/**
	 * Whether a given `wp_options.option_name` matches one of this
	 * rule's configured patterns.
	 */
	public function matchesOptionName( string $optionName ): bool {
		foreach ( $this->optionKeys as $pattern ) {
			if ( str_ends_with( $pattern, '*' ) ) {
				if ( str_starts_with( $optionName, substr( $pattern, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $optionName === $pattern ) {
				return true;
			}
		}

		return false;
	}
}
