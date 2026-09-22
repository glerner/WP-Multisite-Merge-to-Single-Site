<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Picks a user's single destination role from the union of roles held
 * across included sites: the HIGHEST role wins (PLAN.md §7.3).
 *
 * Pure logic, no DB dependency, so it is fully unit-testable.
 *
 * @package MergeMultisite
 */
final class RoleResolver {

	/**
	 * Core role ranks. Custom roles (membership/LMS plugins add many)
	 * rank below subscriber: they still migrate by name so their
	 * plugin can interpret them on the destination.
	 *
	 * @var array<string, int>
	 */
	private const ROLE_RANKS = array(
		'administrator' => 50,
		'editor' => 40,
		'author' => 30,
		'contributor' => 20,
		'subscriber' => 10,
	);

	/**
	 * The wp_user_level values WordPress still writes; kept consistent
	 * with the resolved role.
	 *
	 * @var array<string, int>
	 */
	private const ROLE_LEVELS = array(
		'administrator' => 10,
		'editor' => 7,
		'author' => 2,
		'contributor' => 1,
		'subscriber' => 0,
	);

	/**
	 * The highest-ranked role from a set of role slugs. Ties and
	 * unknown slugs resolve deterministically (alphabetically) so a
	 * re-run can't flip the answer.
	 *
	 * @param string[] $roles
	 */
	public function highest( array $roles ): ?string {
		$best = null;
		$bestRank = -1;
		foreach ( $roles as $role ) {
			$rank = self::ROLE_RANKS[ $role ] ?? 0;
			if ( $rank > $bestRank || ( $rank === $bestRank && $role < $best ) ) {
				$best = $role;
				$bestRank = $rank;
			}
		}

		return $best;
	}

	/**
	 * The legacy wp_user_level matching a role (0 for custom roles).
	 */
	public function userLevel( string $role ): int {
		return self::ROLE_LEVELS[ $role ] ?? 0;
	}
}
