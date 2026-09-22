<?php

declare(strict_types=1);

namespace MergeMultisite\Db;

use MergeMultisite\Config\DatabaseConfig;
use PDO;
use PDOException;

/**
 * A thin PDO wrapper for one WordPress database (source or
 * destination), providing lazy connection and a couple of
 * WordPress-table-name-aware convenience methods.
 *
 * @package MergeMultisite
 */
final class Connection {

	private ?PDO $pdo = null;

	public function __construct( public readonly DatabaseConfig $config ) {
	}

	/**
	 * @throws ConnectionException If the connection cannot be established.
	 */
	public function pdo(): PDO {
		if ( $this->pdo === null ) {
			if ( $this->config->socket !== null ) {
				$dsn = sprintf(
					'mysql:unix_socket=%s;dbname=%s;charset=%s',
					$this->config->socket,
					$this->config->database,
					$this->config->charset
				);
			} else {
				$dsn = sprintf(
					'mysql:host=%s;port=%d;dbname=%s;charset=%s',
					$this->config->host,
					$this->config->port,
					$this->config->database,
					$this->config->charset
				);
			}

			try {
				$this->pdo = new PDO(
					$dsn,
					$this->config->username,
					$this->config->password,
					array(
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES => false,
					)
				);
			} catch ( PDOException $exception ) {
				throw new ConnectionException(
					$this->connectionFailureMessage( $exception ),
					(int) $exception->getCode(),
					$exception
				);
			}
		}

		return $this->pdo;
	}

	/**
	 * Build an error message that names the failing config section and
	 * lists the most likely fixes, instead of a bare PDO stack trace.
	 */
	private function connectionFailureMessage( PDOException $exception ): string {
		$label = $this->config->label;
		$target = $this->config->socket !== null
			? sprintf( 'socket %s, schema "%s"', $this->config->socket, $this->config->database )
			: sprintf( '%s@%s:%d, schema "%s"', $this->config->username, $this->config->host, $this->config->port, $this->config->database );

		return <<<EOT
Could not connect to the database configured under "{$label}" ({$target}).
PDO error: {$exception->getMessage()}

Troubleshooting:
  - If this database runs under Lando: the host port is reassigned each
    time the container is recreated. Set "connection" => ["driver" =>
    "lando"] under "{$label}" in config/config.php to resolve it
    automatically, or find the current port with `lando info` /
    `docker ps` and update "port" by hand.
  - If this database runs under Local by Flywheel: set "connection" =>
    ["driver" => "local", "site" => "<name>"] and make sure the site is
    running (its MySQL socket only exists while it is).
  - If "host" is a Docker service name (e.g. "database"), it only resolves
    inside the Docker network; from the host machine use 127.0.0.1 with
    the mapped port instead.
  - Otherwise confirm MySQL is running and that the host, port, and
    credentials in config/config.php are correct.
EOT;
	}

	/**
	 * Build the table name for a given base table on a given site.
	 *
	 * @see DatabaseConfig::siteTable()
	 */
	public function siteTable( string $baseTable, int $blogId ): string {
		return $this->config->siteTable( $baseTable, $blogId );
	}

	/**
	 * Build the name of a network-wide (not per-site) table.
	 *
	 * @see DatabaseConfig::networkTable()
	 */
	public function networkTable( string $baseTable ): string {
		return $this->config->networkTable( $baseTable );
	}

	/**
	 * Run a SELECT query and return all matching rows.
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function fetchAll( string $sql, array $params = array() ): array {
		$statement = $this->pdo()->prepare( $sql );
		$statement->execute( $params );

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $statement->fetchAll();

		return $rows;
	}

	/**
	 * Run a SELECT query and return the first matching row, or null.
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return array<string, mixed>|null
	 */
	public function fetchOne( string $sql, array $params = array() ): ?array {
		$statement = $this->pdo()->prepare( $sql );
		$statement->execute( $params );

		$row = $statement->fetch();

		return $row === false ? null : $row;
	}

	/**
	 * Run a SELECT COUNT(*)-style query and return a single scalar value.
	 *
	 * @param array<string, mixed> $params
	 */
	public function fetchScalar( string $sql, array $params = array() ): mixed {
		$statement = $this->pdo()->prepare( $sql );
		$statement->execute( $params );

		$value = $statement->fetchColumn();

		return $value === false ? null : $value;
	}

	/**
	 * Execute an INSERT/UPDATE/DELETE statement and return the number
	 * of affected rows.
	 *
	 * @param array<string, mixed> $params
	 */
	public function execute( string $sql, array $params = array() ): int {
		$statement = $this->pdo()->prepare( $sql );
		$statement->execute( $params );

		return $statement->rowCount();
	}

	/**
	 * The auto-increment ID from the most recent INSERT.
	 */
	public function lastInsertId(): int {
		return (int) $this->pdo()->lastInsertId();
	}

	/**
	 * Open a transaction on this connection. Callers must keep DDL
	 * outside transactions -- MySQL implicitly commits before and
	 * after CREATE/ALTER, which would silently commit pending writes
	 * and make rollBack() a no-op.
	 */
	public function beginTransaction(): void {
		$this->pdo()->beginTransaction();
	}

	/**
	 * Commit the current transaction.
	 */
	public function commit(): void {
		$this->pdo()->commit();
	}

	/**
	 * Roll back the current transaction.
	 */
	public function rollBack(): void {
		$this->pdo()->rollBack();
	}

	/**
	 * Whether a transaction is currently open on this connection.
	 */
	public function inTransaction(): bool {
		return $this->pdo !== null && $this->pdo->inTransaction();
	}

	/**
	 * Determine whether a table exists in this connection's database.
	 */
	public function tableExists( string $tableName ): bool {
		$row = $this->fetchOne(
			'SELECT TABLE_NAME FROM information_schema.TABLES '
			. 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table',
			array(
			'schema' => $this->config->database,
			'table' => $tableName,
			)
		);

		return $row !== null;
	}
}
