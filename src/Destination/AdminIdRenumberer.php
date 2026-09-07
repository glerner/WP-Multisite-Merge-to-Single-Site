<?php

declare(strict_types=1);

namespace MergeMultisite\Destination;

/**
 * Builds the SQL needed to renumber a freshly-created WordPress
 * install's admin user away from ID 1.
 *
 * This is defense-in-depth against username-enumeration scripts that
 * probe `?author=1` (WordPress's default author-archive redirect
 * reveals that user's username), not a fix for SQL injection --
 * parameterized queries (used throughout this project) are what
 * prevent that, regardless of which ID a user has.
 *
 * Intended to run once, on a freshly created destination site, before
 * `admin_user_id` is set in `config.php` and before `migrate.php` is
 * run against that destination.
 *
 * @package MergeMultisite
 */
final class AdminIdRenumberer {
	/**
	 * Picks a random replacement ID. Defaults to 6-105 (a random
	 * 1-100 plus an offset of 5), avoiding the low single-digit IDs a
	 * brute-force/enumeration script would try first, while still
	 * being nowhere near real users' migrated IDs once migrate.php
	 * has run.
	 *
	 * @throws \InvalidArgumentException If the range excludes $oldId is impossible to satisfy.
	 */
	public function pickRandomId( int $oldId = 1, int $rangeMin = 1, int $rangeMax = 100, int $offset = 5 ): int {
		$min = $rangeMin + $offset;
		$max = $rangeMax + $offset;

		if ( $min > $max ) {
			throw new \InvalidArgumentException( 'rangeMin must not be greater than rangeMax.' );
		}

		do {
			$candidate = random_int( $min, $max );
		} while ( $candidate === $oldId );

		return $candidate;
	}

	/**
	 * Builds the ordered list of SQL statements to run, in a single
	 * transaction, to move a user from $oldId to $newId and keep
	 * every table that references that user_id/post_author in sync.
	 *
	 * @return string[]
	 *
	 * @throws \InvalidArgumentException If $oldId equals $newId.
	 */
	public function buildStatements( int $oldId, int $newId, string $tablePrefix ): array {
		if ( $oldId === $newId ) {
			throw new \InvalidArgumentException( 'oldId and newId must differ.' );
		}

		return array(
			// Must be first: usermeta/posts/comments below have no FK
			// enforcing referential integrity in WordPress's schema, so
			// order only matters for readability here, not correctness.
			sprintf( 'UPDATE %susers SET ID = %d WHERE ID = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %susermeta SET user_id = %d WHERE user_id = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %sposts SET post_author = %d WHERE post_author = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %scomments SET user_id = %d WHERE user_id = %d', $tablePrefix, $newId, $oldId ),
			// Keeps future auto-registered users from being assigned a
			// low ID that collides with old bookmarks/assumptions.
			sprintf( 'ALTER TABLE %susers AUTO_INCREMENT = %d', $tablePrefix, $newId + 1 ),
		);
	}
}
