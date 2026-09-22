<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Support\Logger;

/**
 * Migrates wp_users + wp_usermeta from the multisite's network-wide
 * user tables into the destination single site (PLAN.md §7.3).
 *
 * A source user migrates if they hold capabilities on at least one
 * INCLUDED site. Their destination role is the highest role held
 * across those sites (RoleResolver); all other usermeta copies
 * verbatim except per-site capabilities/user_level keys, which the
 * resolved role replaces.
 *
 * A destination user already holding the same user_login or
 * user_email is treated as the same person: the source user links to
 * the existing destination ID instead of inserting (reported as
 * 'linked'), and their meta is not overwritten.
 *
 * @package MergeMultisite
 */
final class UserMigrator {

	private const USER_COLUMNS = array(
		'user_login',
		'user_pass',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_activation_key',
		'user_status',
		'display_name',
	);

	public function __construct( private readonly RoleResolver $roles = new RoleResolver() ) {
	}

	/**
	 * Runs the user phase.
	 *
	 * @param Site[] $sites Included sites only.
	 *
	 * @return array{migrated:int, linked:array<int, string>, skipped:int, roles:array<int, string>, conflicts:array<int, string>}
	 *         Counts and per-user report lines.
	 *
	 * @throws \Throwable When a batch fails (rolled back first).
	 */
	public function migrate(
		Connection $source,
		Connection $destination,
		MergeConfig $config,
		array $sites,
		IdMap $idMap,
		MigrationTable $mapTable,
		bool $dryRun,
		Logger $logger
	): array {
		$usersTable = $source->networkTable( 'users' );
		$userMetaTable = $source->networkTable( 'usermeta' );
		$destUsers = $destination->networkTable( 'users' );
		$destUserMeta = $destination->networkTable( 'usermeta' );

		// Roles held per user across included sites. The capabilities
		// meta key follows the same {prefix}{blogId}_ convention as
		// site table names (main site uses the bare prefix).
		$rolesByUser = array();
		$sitesByUser = array();
		foreach ( $sites as $site ) {
			$capKey = $source->siteTable( 'capabilities', $site->blogId );
			foreach (
				$source->fetchAll(
					"SELECT user_id, meta_value FROM {$userMetaTable} WHERE meta_key = :key",
					array( 'key' => $capKey )
				) as $row
			) {
				$caps = @unserialize( (string) $row['meta_value'] );
				if ( ! is_array( $caps ) ) {
					continue;
				}
				$userId = (int) $row['user_id'];
				foreach ( array_keys( array_filter( $caps ) ) as $role ) {
					$rolesByUser[ $userId ][ (string) $role ] = true;
					$sitesByUser[ $userId ][ $site->blogId ] = true;
				}
			}
		}

		// Destination users keyed by login and email, for the
		// same-person link decision.
		$destByLogin = array();
		$destByEmail = array();
		foreach ( $destination->fetchAll( "SELECT ID, user_login, user_email FROM {$destUsers}" ) as $row ) {
			$destByLogin[ (string) $row['user_login'] ] = (int) $row['ID'];
			$destByEmail[ strtolower( (string) $row['user_email'] ) ] = (int) $row['ID'];
		}

		$report = array(
			'migrated' => 0,
			'linked' => array(),
			'skipped' => 0,
			'roles' => array(),
			'conflicts' => array(),
		);

		$userIds = array_keys( $rolesByUser );
		foreach ( array_chunk( $userIds, max( 1, $config->batchSize ) ) as $batch ) {
			if ( ! $dryRun ) {
				$destination->beginTransaction();
			}

			try {
				foreach ( $batch as $userId ) {
					$user = $this->fetchUser( $source, $usersTable, $userId );
					if ( $user === null ) {
						++$report['skipped'];
						continue;
					}

					if ( $idMap->has( 'user', 0, $userId ) ) {
						++$report['skipped'];
						continue;
					}

					$login = (string) $user['user_login'];
					$email = strtolower( (string) $user['user_email'] );

					// Same login AND same email: same person -> link.
					// Mismatched pairings are genuine conflicts to flag.
					$loginOwner = $destByLogin[ $login ] ?? null;
					$emailOwner = $destByEmail[ $email ] ?? null;

					if ( $loginOwner !== null && $emailOwner === $loginOwner ) {
						$idMap->set( 'user', 0, $userId, $loginOwner );
						if ( ! $dryRun ) {
							$mapTable->record( $destination, 'user', 0, $userId, $loginOwner );
						}
						$report['linked'][] = sprintf( '"%s" -> existing destination user #%d', $login, $loginOwner );
						continue;
					}

					if ( $loginOwner !== null || $emailOwner !== null ) {
						$report['conflicts'][] = sprintf(
							'"%s" <%s>: destination already has %s -- resolve manually',
							$login,
							$email,
							$loginOwner !== null ? 'that login' : 'that email'
						);
						continue;
					}

					if ( $dryRun ) {
						++$report['migrated'];
					} else {
						$newId = $this->insertUser( $destination, $destUsers, $user );
						$this->copyUserMeta( $source, $destination, $userMetaTable, $destUserMeta, $config, $userId, $newId );

						$role = $this->roles->highest( array_keys( $rolesByUser[ $userId ] ) );
						if ( $role !== null ) {
							$this->writeRole( $destination, $destUserMeta, $config, $newId, $role );
							$report['roles'][] = sprintf(
								'"%s" -> %s (roles on sites %s: %s)',
								$login,
								$role,
								implode( ',', array_keys( $sitesByUser[ $userId ] ) ),
								implode( ',', array_keys( $rolesByUser[ $userId ] ) )
							);
						}

						$idMap->set( 'user', 0, $userId, $newId );
						$mapTable->record( $destination, 'user', 0, $userId, $newId );
						++$report['migrated'];
					}
				}

				if ( ! $dryRun ) {
					$destination->commit();
				}
			} catch ( \Throwable $exception ) {
				if ( $destination->inTransaction() ) {
					$destination->rollBack();
				}
				throw $exception;
			}
		}

		$logger->info(
			sprintf(
				'Users: %d migrated, %d linked to existing, %d skipped, %d conflict(s).',
				$report['migrated'],
				count( $report['linked'] ),
				$report['skipped'],
				count( $report['conflicts'] )
			)
		);

		return $report;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetchUser( Connection $source, string $usersTable, int $userId ): ?array {
		return $source->fetchOne(
			"SELECT * FROM {$usersTable} WHERE ID = :id",
			array( 'id' => $userId )
		);
	}

	/**
	 * @param array<string, mixed> $user
	 */
	private function insertUser( Connection $destination, string $destUsers, array $user ): int {
		$columns = implode( ', ', self::USER_COLUMNS );
		$placeholders = ':' . implode( ', :', self::USER_COLUMNS );
		$params = array();
		foreach ( self::USER_COLUMNS as $column ) {
			$params[ $column ] = $user[ $column ];
		}

		$destination->execute(
			"INSERT INTO {$destUsers} ({$columns}) VALUES ({$placeholders})",
			$params
		);

		return $destination->lastInsertId();
	}

	/**
	 * Copies all usermeta except the per-site capabilities/user_level
	 * keys (which the resolved destination role replaces). Per-site
	 * keys match "{basePrefix}capabilities", "{basePrefix}user_level",
	 * or "{basePrefix}{blogId}_capabilities" etc.
	 */
	private function copyUserMeta(
		Connection $source,
		Connection $destination,
		string $userMetaTable,
		string $destUserMeta,
		MergeConfig $config,
		int $oldId,
		int $newId
	): void {
		$prefix = preg_quote( $source->config->tablePrefix, '/' );
		$siteKeyPattern = '/^' . $prefix . '([0-9]+_)?(capabilities|user_level)$/';

		foreach (
			$source->fetchAll(
				"SELECT meta_key, meta_value FROM {$userMetaTable} WHERE user_id = :id",
				array( 'id' => $oldId )
			) as $row
		) {
			if ( preg_match( $siteKeyPattern, (string) $row['meta_key'] ) === 1 ) {
				continue;
			}

			$destination->execute(
				"INSERT INTO {$destUserMeta} (user_id, meta_key, meta_value) VALUES (:uid, :key, :value)",
				array(
				'uid' => $newId,
				'key' => $row['meta_key'],
				'value' => $row['meta_value'],
				)
			);
		}
	}

	/**
	 * Writes the destination capabilities + user_level for the
	 * resolved role, using the DESTINATION's own table prefix in the
	 * meta keys (a destination prefix needn't match the source's).
	 */
	private function writeRole(
		Connection $destination,
		string $destUserMeta,
		MergeConfig $config,
		int $newId,
		string $role
	): void {
		$prefix = $destination->config->tablePrefix;
		foreach (
			array(
				$prefix . 'capabilities' => serialize( array( $role => true ) ),
				$prefix . 'user_level' => (string) $this->roles->userLevel( $role ),
			) as $key => $value
		) {
			$destination->execute(
				"INSERT INTO {$destUserMeta} (user_id, meta_key, meta_value) VALUES (:uid, :key, :value)",
				array(
				'uid' => $newId,
				'key' => $key,
				'value' => $value,
				)
			);
		}
	}
}
