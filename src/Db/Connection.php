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
	 * @throws PDOException If the connection cannot be established.
	 */
	public function pdo(): PDO {
		if ( $this->pdo === null ) {
			$dsn = sprintf(
				'mysql:host=%s;port=%d;dbname=%s;charset=%s',
				$this->config->host,
				$this->config->port,
				$this->config->database,
				$this->config->charset
			);

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
		}

		return $this->pdo;
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
