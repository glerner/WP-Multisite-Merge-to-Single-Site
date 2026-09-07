<?php

declare(strict_types=1);

namespace MergeMultisite\Audit\Checks;

use MergeMultisite\Audit\AuditCheckInterface;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;

/**
 * Defensively checks the network-wide `users` table for conditions
 * that a genuine WordPress multisite should never produce (users are
 * global, with unique `user_login` and `user_email`), but that could
 * appear in data that was not always one clean network. See PLAN.md
 * §7.3 for the three cases checked here.
 *
 * @package MergeMultisite
 */
final class UserConflictCheck implements AuditCheckInterface {

	public function name(): string {
		return 'user-conflict';
	}

	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$usersTable = $source->networkTable( 'users' );

		$users = $source->fetchAll(
			"SELECT ID, user_login, user_email, display_name FROM {$usersTable} ORDER BY ID"
		);

		$findings = array();
		$findings = array( ...$findings, ...$this->checkSameEmailDifferentLogin( $users ) );
		$findings = array( ...$findings, ...$this->checkSameLoginDifferentEmail( $users ) );

		return $findings;
	}

	/**
	 * @param array<int, array<string, mixed>> $users
	 *
	 * @return AuditFinding[]
	 */
	private function checkSameEmailDifferentLogin( array $users ): array {
		$byEmail = array();
		foreach ( $users as $user ) {
			$email = strtolower( (string) $user['user_email'] );
			$byEmail[ $email ][] = $user;
		}

		$findings = array();
		foreach ( $byEmail as $email => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$logins = array_unique( array_map( static fn ( array $u ): string => (string) $u['user_login'], $group ) );
			if ( count( $logins ) < 2 ) {
				continue; // Same login too -- not a conflict, just the same user found twice.
			}

			$findings[] = AuditFinding::warning(
				$this->name(),
				sprintf(
					'Email "%s" is shared by %d users with different logins (%s) -- likely the same person under different accounts; review manually.',
					$email,
					count( $group ),
					implode( ', ', $logins )
				),
				array(
				'email' => $email,
				'user_ids' => array_column( $group, 'ID' ),
				)
			);
		}

		return $findings;
	}

	/**
	 * @param array<int, array<string, mixed>> $users
	 *
	 * @return AuditFinding[]
	 */
	private function checkSameLoginDifferentEmail( array $users ): array {
		$byLogin = array();
		foreach ( $users as $user ) {
			$login = strtolower( (string) $user['user_login'] );
			$byLogin[ $login ][] = $user;
		}

		$findings = array();
		foreach ( $byLogin as $login => $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$emails = array_unique( array_map( static fn ( array $u ): string => strtolower( (string) $u['user_email'] ), $group ) );
			if ( count( $emails ) < 2 ) {
				continue;
			}

			$findings[] = AuditFinding::error(
				$this->name(),
				sprintf(
					'Login "%s" is shared by %d users with different emails (%s) -- WordPress should never allow this in one network; the source data needs manual attention before migrating.',
					$login,
					count( $group ),
					implode( ', ', $emails )
				),
				array(
				'login' => $login,
				'user_ids' => array_column( $group, 'ID' ),
				)
			);
		}

		return $findings;
	}
}
