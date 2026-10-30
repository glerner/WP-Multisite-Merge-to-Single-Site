<?php

declare(strict_types=1);

namespace MergeMultisite\Destination;

use MergeMultisite\Db\Connection;
use MergeMultisite\Db\ConnectionException;

/**
 * Direct-database updates to the destination site's admin account.
 *
 * WordPress refuses user_login changes through every official channel:
 * wp_update_user(), wp-admin, and WP-CLI all reject them. Nothing at
 * the schema level prevents it, because posts and comments reference
 * the user by ID. A direct UPDATE on the users table is the escape
 * hatch for a login that was misspelled or regretted at install time.
 *
 * user_nicename is rewritten alongside user_login, so author-archive
 * URLs (/author/<nicename>/) stop exposing the old login.
 *
 * setEmail() exists for the mirror-image case: a local-only testing
 * address that must become the real address on the finalizing run.
 *
 * @package MergeMultisite
 */
final class AdminAccountUpdater {

	/**
	 * Returns null when $login is a storable user_login, else a
	 * human-readable reason it is not.
	 */
	public function validateLogin( string $login ): ?string {
		if ( $login === '' ) {
			return 'must not be empty';
		}
		if ( strlen( $login ) > 60 ) {
			return sprintf(
				'is %d characters long; wp_users.user_login holds at most 60, and MySQL would silently truncate it',
				strlen( $login )
			);
		}
		$sanitized = $this->sanitizeLogin( $login );
		if ( $sanitized !== $login ) {
			return sprintf(
				'contains characters WordPress strips from user_login; it would be stored as "%s"',
				$sanitized
			);
		}

		return null;
	}

	/**
	 * The character set WordPress's sanitize_user() allows in strict
	 * mode: letters, digits, space, underscore, dot, hyphen, at-sign.
	 * Only used to predict what WordPress would store; anything this
	 * filter changes is rejected by validateLogin().
	 */
	public function sanitizeLogin( string $login ): string {
		return (string) preg_replace( '|[^a-z0-9 _.\-@]|i', '', $login );
	}

	/**
	 * Close approximation of sanitize_title(), for the user_nicename
	 * WordPress derives from user_login at insert time.
	 */
	public function nicenameFor( string $login ): string {
		$nicename = strtolower( $login );
		$nicename = (string) preg_replace( '/[\s.]+/', '-', $nicename );
		$nicename = (string) preg_replace( '/[^a-z0-9_\-]/', '', $nicename );
		$nicename = (string) preg_replace( '/-{2,}/', '-', $nicename );

		return trim( $nicename, '-' );
	}

	/**
	 * Applies the rename: user_login and user_nicename in one UPDATE.
	 *
	 * @throws ConnectionException When the UPDATE fails.
	 */
	public function renameLogin( Connection $destination, int $userId, string $newLogin ): void {
		$destination->execute(
			sprintf(
				'UPDATE %susers SET user_login = :login, user_nicename = :nicename WHERE ID = :id',
				$destination->config->tablePrefix
			),
			array(
				'login'    => $newLogin,
				'nicename' => $this->nicenameFor( $newLogin ),
				'id'       => $userId,
			)
		);
	}

	/**
	 * Sets the account's user_email in one UPDATE.
	 *
	 * @throws ConnectionException When the UPDATE fails.
	 */
	public function setEmail( Connection $destination, int $userId, string $email ): void {
		$destination->execute(
			sprintf(
				'UPDATE %susers SET user_email = :email WHERE ID = :id',
				$destination->config->tablePrefix
			),
			array(
				'email' => $email,
				'id'    => $userId,
			)
		);
	}
}
