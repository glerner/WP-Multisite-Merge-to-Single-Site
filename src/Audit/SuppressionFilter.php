<?php

declare(strict_types=1);

namespace MergeMultisite\Audit;

/**
 * Applies the "suppressions" rules from config.php to a collected set
 * of findings, so known-benign or already-decided findings don't flood
 * the report. Suppression is never silent: when at least one finding is
 * suppressed, a single summary finding ("audit.suppressed") is appended
 * so the report still records that filtering happened.
 *
 * Rule shape (each entry in the "suppressions" config array):
 *
 *   ['check' => 'orphaned-post-author.no-author']   exact finding name
 *   ['check' => 'plugin-data.*']                    trailing * = prefix
 *   ['check' => 'media-files.missing-file', 'blog_id' => 26]
 *                                                   extra keys must match
 *                                                   the finding's context
 *
 * A context criterion matches when the finding's context value equals
 * the rule value, or -- when the context value is a list -- contains it.
 * Comparison is string-based so 26 and "26" are interchangeable.
 *
 * @package MergeMultisite
 */
final class SuppressionFilter {

	/**
	 * @param AuditFinding[]                   $findings
	 * @param array<int, array<string, mixed>> $rules
	 *
	 * @return AuditFinding[]
	 */
	public static function apply( array $findings, array $rules ): array {
		if ( $rules === array() ) {
			return $findings;
		}

		$kept = array();
		$suppressed = 0;

		foreach ( $findings as $finding ) {
			if ( self::matchesAny( $finding, $rules ) ) {
				$suppressed++;
				continue;
			}
			$kept[] = $finding;
		}

		if ( $suppressed > 0 ) {
			$kept[] = AuditFinding::info(
				'audit.suppressed',
				sprintf(
					'%d finding(s) hidden by "suppressions" rules in config.php; to review them, temporarily remove the matching rules.',
					$suppressed
				),
				array( 'suppressed_count' => $suppressed )
			);
		}

		return $kept;
	}

	/**
	 * @param array<int, array<string, mixed>> $rules
	 */
	private static function matchesAny( AuditFinding $finding, array $rules ): bool {
		foreach ( $rules as $rule ) {
			if ( self::matches( $finding, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $rule
	 */
	private static function matches( AuditFinding $finding, array $rule ): bool {
		$checkPattern = (string) ( $rule['check'] ?? '' );

		if ( str_ends_with( $checkPattern, '*' ) ) {
			if ( ! str_starts_with( $finding->checkName, substr( $checkPattern, 0, -1 ) ) ) {
				return false;
			}
		} elseif ( $finding->checkName !== $checkPattern ) {
			return false;
		}

		foreach ( $rule as $key => $wanted ) {
			if ( $key === 'check' ) {
				continue;
			}

			$actual = $finding->context[ (string) $key ] ?? null;

			if ( is_array( $actual ) ) {
				$matched = false;
				foreach ( $actual as $item ) {
					if ( is_scalar( $item ) && (string) $item === (string) $wanted ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					return false;
				}
			} elseif ( ! is_scalar( $actual ) || (string) $actual !== (string) $wanted ) {
				return false;
			}
		}

		return true;
	}
}
