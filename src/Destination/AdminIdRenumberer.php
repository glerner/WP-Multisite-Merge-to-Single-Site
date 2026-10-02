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

		if ( $min === $max && $min === $oldId ) {
			throw new \InvalidArgumentException( 'Effective range contains only oldId; cannot pick a different ID.' );
		}

		do {
			$candidate = random_int( $min, $max );
		} while ( $candidate === $oldId );

		return $candidate;
	}

	/**
	 * Builds the ordered list of transactional DML statements (UPDATEs)
	 * to move a user from $oldId to $newId across users, usermeta, posts,
	 * and comments tables.
	 *
	 * @return string[]
	 *
	 * @throws \InvalidArgumentException If $oldId equals $newId.
	 */
	public function buildDmlStatements( int $oldId, int $newId, string $tablePrefix ): array {
		if ( $oldId === $newId ) {
			throw new \InvalidArgumentException( 'oldId and newId must differ.' );
		}

		return array(
			sprintf( 'UPDATE %susers SET ID = %d WHERE ID = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %susermeta SET user_id = %d WHERE user_id = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %sposts SET post_author = %d WHERE post_author = %d', $tablePrefix, $newId, $oldId ),
			sprintf( 'UPDATE %scomments SET user_id = %d WHERE user_id = %d', $tablePrefix, $newId, $oldId ),
		);
	}

	/**
	 * Builds non-transactional DDL statements (ALTER TABLE) to update
	 * AUTO_INCREMENT so new auto-registered users cannot collide with
	 * old IDs. Must run outside transactions because MySQL DDL commits
	 * implicitly.
	 *
	 * Relaxes NO_ZERO_DATE in session sql_mode so MySQL 8.0 strict mode
	 * does not reject ALTER TABLE with "1067 Invalid default value for
	 * 'user_registered'" due to WordPress's legacy 0000-00-00 default.
	 *
	 * @return string[]
	 */
	public function buildDdlStatements( int $newId, string $tablePrefix ): array {
		return array(
			"SET SESSION sql_mode = REPLACE(REPLACE(@@sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '')",
			sprintf( 'ALTER TABLE %susers AUTO_INCREMENT = %d', $tablePrefix, $newId + 1 ),
		);
	}

	/**
	 * Builds the complete ordered list of SQL statements (DML updates
	 * followed by DDL auto-increment update).
	 *
	 * @return string[]
	 *
	 * @throws \InvalidArgumentException If $oldId equals $newId.
	 */
	public function buildStatements( int $oldId, int $newId, string $tablePrefix ): array {
		return array(
			...$this->buildDmlStatements( $oldId, $newId, $tablePrefix ),
			...$this->buildDdlStatements( $newId, $tablePrefix ),
		);
	}
}
