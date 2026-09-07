<?php

declare(strict_types=1);

namespace MergeMultisite\Config;

/**
 * Immutable connection settings for one WordPress database (either the
 * source multisite network or the destination single site).
 *
 * @package MergeMultisite
 */
final class DatabaseConfig {

	/**
	 * @param string   $host         Database host name or IP address.
	 * @param int      $port         Database port.
	 * @param string   $database     Database (schema) name.
	 * @param string   $username     Database username.
	 * @param string   $password     Database password.
	 * @param string   $charset      Connection charset, e.g. "utf8mb4".
	 * @param string   $tablePrefix  Base WordPress table prefix, e.g. "wp_" or "wp3_".
	 *                               This is never assumed to be "wp_" -- it always
	 *                               comes from configuration.
	 * @param string   $uploadsPath  Absolute filesystem path to this site's
	 *                               wp-content/uploads directory.
	 * @param int|null $adminUserId  For the destination only: the user_id of the
	 *                               admin user already created there, which the
	 *                               migrator must never touch or duplicate.
	 */
	public function __construct(
		public readonly string $host,
		public readonly int $port,
		public readonly string $database,
		public readonly string $username,
		public readonly string $password,
		public readonly string $charset,
		public readonly string $tablePrefix,
		public readonly string $uploadsPath,
		public readonly ?int $adminUserId = null,
	) {
	}

	/**
	 * Build an instance from a raw config array (as loaded from
	 * config.php's 'source' or 'destination' key).
	 *
	 * @param array<string, mixed> $data
	 * @param string               $label Human-readable label used in error messages.
	 *
	 * @throws ConfigException If a required key is missing.
	 */
	public static function fromArray( array $data, string $label ): self {
		foreach ( array( 'host', 'database', 'username', 'table_prefix', 'uploads_path' ) as $required ) {
			if ( ! array_key_exists( $required, $data ) || $data[ $required ] === '' ) {
				throw new ConfigException(
					sprintf( 'Missing required "%s" config key for "%s" database.', $required, $label )
				);
			}
		}

		return new self(
			host: (string) $data['host'],
			port: (int) ( $data['port'] ?? 3306 ),
			database: (string) $data['database'],
			username: (string) $data['username'],
			password: (string) ( $data['password'] ?? '' ),
			charset: (string) ( $data['charset'] ?? 'utf8mb4' ),
			tablePrefix: (string) $data['table_prefix'],
			uploadsPath: (string) $data['uploads_path'],
			adminUserId: isset( $data['admin_user_id'] ) ? (int) $data['admin_user_id'] : null,
		);
	}

	/**
	 * Build the table name for a given base table on a given site.
	 *
	 * Mirrors WordPress's own multisite table-naming convention: the
	 * network's main site (blog_id 1) uses the bare prefix, every other
	 * site inserts its blog ID, e.g. `wp_2_posts`.
	 *
	 * @param string $baseTable e.g. "posts", "postmeta", "terms".
	 * @param int    $blogId    The site's blog_id.
	 */
	public function siteTable( string $baseTable, int $blogId ): string {
		if ( $blogId <= 1 ) {
			return $this->tablePrefix . $baseTable;
		}

		return $this->tablePrefix . $blogId . '_' . $baseTable;
	}

	/**
	 * Build the name of a network-wide table (not per-site), e.g.
	 * `users`, `usermeta`, `blogs`, `site`.
	 *
	 * @param string $baseTable e.g. "users", "blogs".
	 */
	public function networkTable( string $baseTable ): string {
		return $this->tablePrefix . $baseTable;
	}
}
