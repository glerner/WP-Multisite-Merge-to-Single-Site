<?php

declare(strict_types=1);

namespace MergeMultisite\ContentAudit\Detectors;

/**
 * Merge helpers for the 'detector_extras' section of
 * config/plugin-roles.php: per-detector signature-table overrides,
 * keyed by each detector's category() string.
 *
 * The detectors' built-in tables stay in source (they encode the
 * detection mechanics); extras AUGMENT them so a newly-discovered
 * plugin signature is a config edit, not a code edit. Extras can add
 * or extend entries but never remove a built-in.
 *
 * @package MergeMultisite
 */
final class DetectorExtras {

	/**
	 * Merge a "label => regex patterns" signature map (e.g. content
	 * or form-action signatures): patterns for an existing label are
	 * APPENDED (the config can teach a built-in label another
	 * signature); a new label adds a new entry.
	 *
	 * @param array<string, string[]> $builtin Built-in signature map.
	 * @param mixed                   $extras  Raw config value.
	 *
	 * @return array<string, string[]>
	 */
	public static function patternMap( array $builtin, mixed $extras ): array {
		if ( ! is_array( $extras ) ) {
			return $builtin;
		}

		foreach ( $extras as $label => $patterns ) {
			if ( ! is_array( $patterns ) ) {
				continue;
			}
			$label = (string) $label;
			$builtin[ $label ] = array_merge(
				$builtin[ $label ] ?? array(),
				array_map( 'strval', $patterns )
			);
		}

		return $builtin;
	}

	/**
	 * Merge a "label => [meta_key, needle]" signature map (postmeta
	 * signatures): an existing label's tuple is REPLACED, a new label
	 * is added. Tuples shorter than two elements are ignored.
	 *
	 * @param array<string, array{0: string, 1: string}> $builtin Built-in signature map.
	 * @param mixed                                      $extras  Raw config value.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function tupleMap( array $builtin, mixed $extras ): array {
		if ( ! is_array( $extras ) ) {
			return $builtin;
		}

		foreach ( $extras as $label => $tuple ) {
			if ( ! is_array( $tuple ) || count( $tuple ) < 2 ) {
				continue;
			}
			$values = array_values( $tuple );
			$builtin[ (string) $label ] = array( (string) $values[0], (string) $values[1] );
		}

		return $builtin;
	}

	/**
	 * Merge a "prefix => label" map (e.g. SEO meta-key prefixes): a
	 * new prefix is added; a known prefix's label is replaced.
	 * Non-string labels are ignored.
	 *
	 * @param array<string, string> $builtin Built-in prefix map.
	 * @param mixed                 $extras  Raw config value.
	 *
	 * @return array<string, string>
	 */
	public static function prefixMap( array $builtin, mixed $extras ): array {
		if ( ! is_array( $extras ) ) {
			return $builtin;
		}

		foreach ( $extras as $prefix => $label ) {
			if ( ! is_string( $label ) ) {
				continue;
			}
			$builtin[ (string) $prefix ] = $label;
		}

		return $builtin;
	}

	/**
	 * Pull one detector's extras block out of the whole
	 * 'detector_extras' config section, keyed by its category().
	 *
	 * @param array<string, mixed> $detectorExtras The whole 'detector_extras' section.
	 *
	 * @return array<string, mixed>
	 */
	public static function forCategory( array $detectorExtras, string $category ): array {
		return is_array( $detectorExtras[ $category ] ?? null ) ? $detectorExtras[ $category ] : array();
	}
}
