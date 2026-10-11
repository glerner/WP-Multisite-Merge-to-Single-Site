<?php

declare(strict_types=1);

namespace MergeMultisite\Destination;

use MergeMultisite\Config\DatabaseConfig;
use MergeMultisite\Db\Connection;

/**
 * Returns the destination database to a known "fresh install" state
 * by dropping every table under the configured table prefix and
 * re-importing a previously snapshotted baseline dump
 * (var/state/destination-baseline.sql, produced by
 * `bin/reset-destination.php --snapshot` at the documented starting
 * state: fresh WP install + harden-admin-id).
 *
 * Why a baseline restore instead of `wp db reset` + `wp core install`:
 * a reinstall recreates the admin as ID 1 with a new password, losing
 * the renumbered admin user that config.php's `admin_user_id` points
 * at (and any other setup applied afterwards). Restoring a dump of
 * the known-good state reproduces it byte-for-byte without needing
 * WP-CLI, Lando, or the admin password.
 *
 * Side effects by design: only rows inside `{prefix}*` tables are
 * touched -- wp-config.php, wp-content (plugins/themes), and
 * merge-multisite's config are files and unaffected. The migration
 * map table shares the prefix and is dropped too, so the next
 * migrate.php run starts from an empty map. Uploaded media files stay
 * on disk -- harmless, since MediaMigrator fingerprint-dedup reuses
 * them -- and can be removed by hand for a truly clean slate.
 *
 * @package MergeMultisite
 */
final class DestinationReset {

	/**
	 * Default baseline dump path, relative to the project root.
	 */
	public const DEFAULT_BASELINE_FILE = 'var/state/destination-baseline.sql';

	/**
	 * Every table in the destination schema whose name starts with the
	 * configured table prefix: WordPress core tables plus the migrator's
	 * `{prefix}merge_migration_map`.
	 *
	 * Lists all tables then filters by prefix in PHP, so the same code
	 * runs on MySQL (SHOW TABLES) and the SQLite test fixtures
	 * (sqlite_master) without LIKE-escaping subtleties.
	 *
	 * @return string[]
	 */
	public function tablesWithPrefix( Connection $db ): array {
		if ( $db->driverName() === 'sqlite' ) {
			$rows = $db->fetchAll( "SELECT name FROM sqlite_master WHERE type = 'table'" );
		} else {
			$rows = $db->fetchAll( 'SHOW TABLES' );
		}

		$prefix = $db->config->tablePrefix;
		$tables = array();
		foreach ( $rows as $row ) {
			$name = (string) array_values( $row )[0];
			if ( str_starts_with( $name, $prefix ) ) {
				$tables[] = $name;
			}
		}

		sort( $tables );

		return $tables;
	}

	/**
	 * Drop the given tables in one statement. WordPress schemas carry
	 * no foreign keys, but checks are disabled first so the drop is
	 * order-proof if a plugin ever adds one.
	 *
	 * @param string[] $tables
	 *
	 * @return int Number of tables dropped.
	 */
	public function dropTables( Connection $db, array $tables ): int {
		if ( $tables === array() ) {
			return 0;
		}

		$quoted = array_map( fn ( string $table ): string => $this->quoteIdentifier( $db, $table ), $tables );

		if ( $db->driverName() === 'sqlite' ) {
			// SQLite has no multi-table DROP and no FOREIGN_KEY_CHECKS
			// switch; PRAGMA foreign_keys covers the same ground.
			$db->execute( 'PRAGMA foreign_keys = OFF' );
			foreach ( $quoted as $table ) {
				$db->execute( 'DROP TABLE IF EXISTS ' . $table );
			}
			$db->execute( 'PRAGMA foreign_keys = ON' );

			return count( $tables );
		}

		$db->execute( 'SET FOREIGN_KEY_CHECKS = 0' );
		$db->execute( 'DROP TABLE IF EXISTS ' . implode( ', ', $quoted ) );
		$db->execute( 'SET FOREIGN_KEY_CHECKS = 1' );

		return count( $tables );
	}

	/**
	 * Shell command that dumps the given tables to a file via
	 * mysqldump. The password travels in MYSQL_PWD rather than --password
	 * so it never appears as a command-line option.
	 *
	 * @param string[] $tables
	 */
	public function dumpCommand( DatabaseConfig $config, array $tables, string $outputPath ): string {
		// Note: no --set-gtid-purged=OFF -- MariaDB's mysqldump rejects
		// it, and local dev servers (Lando/Local) do not enable GTID.
		$args = array_merge(
			array( 'mysqldump', '--single-transaction', '--quick', '--no-tablespaces' ),
			$this->connectionArgs( $config ),
			array( '--default-character-set=' . $config->charset, $config->database ),
			$tables
		);

		return 'MYSQL_PWD=' . escapeshellarg( $config->password )
			. ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) )
			. ' > ' . escapeshellarg( $outputPath );
	}

	/**
	 * Shell command that imports a dump file via the mysql client.
	 * Same MYSQL_PWD treatment as dumpCommand().
	 */
	public function importCommand( DatabaseConfig $config, string $inputPath ): string {
		$args = array_merge(
			array( 'mysql' ),
			$this->connectionArgs( $config ),
			array( '--default-character-set=' . $config->charset, $config->database )
		);

		return 'MYSQL_PWD=' . escapeshellarg( $config->password )
			. ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) )
			. ' < ' . escapeshellarg( $inputPath );
	}

	/**
	 * Connection flags for the mysql client tools: --socket for unix
	 * sockets (Local by Flywheel), --host/--port otherwise.
	 *
	 * @return string[]
	 */
	private function connectionArgs( DatabaseConfig $config ): array {
		$args = array( '--user=' . $config->username );

		if ( $config->socket !== null ) {
			$args[] = '--socket=' . $config->socket;
		} else {
			$args[] = '--host=' . $config->host;
			$args[] = '--port=' . (string) $config->port;
		}

		return $args;
	}

	/**
	 * Quote a table name for the active driver: backticks on MySQL,
	 * double quotes on SQLite.
	 */
	private function quoteIdentifier( Connection $db, string $identifier ): string {
		if ( $db->driverName() === 'sqlite' ) {
			return '"' . str_replace( '"', '""', $identifier ) . '"';
		}

		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
