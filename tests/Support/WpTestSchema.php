<?php

declare(strict_types=1);

namespace MergeMultisite\Tests\Support;

use PDO;

/**
 * Builds minimal WordPress-shaped schemas on a throwaway PDO (CR-401).
 *
 * The column sets mirror WordPress's real tables (so the shared
 * `src/Migration/` read-side SQL runs unmodified) but use SQLite
 * types: `INTEGER PRIMARY KEY` gives the same auto-increment
 * lastInsertId() behaviour as MySQL's `BIGINT UNSIGNED AUTO_INCREMENT`,
 * and TEXT columns accept any value MySQL's VARCHAR/TEXT/ENUM would.
 *
 * Network-wide tables (`users`, `usermeta`, `blogs`) plus per-site
 * tables (`posts`, `postmeta`, `terms`, `term_taxonomy`,
 * `term_relationships`, `options`, `comments`, `commentmeta`) are
 * created for every blog id; the main site (1) uses the bare prefix,
 * every other site `{prefix}{blogId}_`, exactly like WordPress.
 *
 * Insert helpers return the new row's auto-increment ID and default
 * every column WordPress would, so tests only override what they
 * assert against.
 *
 * @package MergeMultisite
 */
final class WpTestSchema {

	/**
	 * The per-site base tables every WordPress site has.
	 */
	private const SITE_TABLES = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'options', 'comments', 'commentmeta' );

	/**
	 * Create the network-wide tables plus per-site tables for every
	 * given blog id.
	 *
	 * @param int[] $blogIds
	 */
	public static function create( PDO $pdo, string $prefix, array $blogIds = array( 1 ) ): void {
		self::networkTables( $pdo, $prefix );
		foreach ( $blogIds as $blogId ) {
			self::siteTables( $pdo, $prefix, $blogId );
		}
	}

	/**
	 * Drop every table this schema created (file-backed fixtures).
	 *
	 * @param int[] $blogIds
	 */
	public static function drop( PDO $pdo, string $prefix, array $blogIds = array( 1 ) ): void {
		$tables = array( 'users', 'usermeta', 'blogs', 'sitemeta' );
		foreach ( $blogIds as $blogId ) {
			foreach ( self::SITE_TABLES as $base ) {
				$tables[] = self::siteTableName( $prefix, $blogId, $base );
			}
		}

		foreach ( $tables as $table ) {
			$pdo->exec( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * The table name a base table gets for a blog id, mirroring
	 * DatabaseConfig::siteTable() (blog 1 = bare prefix).
	 */
	public static function siteTableName( string $prefix, int $blogId, string $baseTable ): string {
		if ( $blogId <= 1 ) {
			return $prefix . $baseTable;
		}

		return $prefix . $blogId . '_' . $baseTable;
	}

	/**
	 * Insert a post row with WordPress defaults, returning its ID.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertPost( PDO $pdo, string $postsTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$postsTable,
			array(
				'post_author'          => 0,
				'post_date'            => '2024-01-01 00:00:00',
				'post_date_gmt'        => '2024-01-01 00:00:00',
				'post_content'         => '',
				'post_title'           => '',
				'post_excerpt'         => '',
				'post_status'          => 'publish',
				'comment_status'       => 'open',
				'ping_status'          => 'open',
				'post_password'        => '',
				'post_name'            => '',
				'to_ping'              => '',
				'pinged'               => '',
				'post_modified'        => '2024-01-01 00:00:00',
				'post_modified_gmt'    => '2024-01-01 00:00:00',
				'post_content_filtered' => '',
				'post_parent'          => 0,
				'guid'                 => '',
				'menu_order'           => 0,
				'post_type'            => 'post',
				'post_mime_type'       => '',
				'comment_count'        => 0,
			),
			$overrides
		);
	}

	/**
	 * Insert a postmeta row, returning its meta_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertPostMeta( PDO $pdo, string $postMetaTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$postMetaTable,
			array(
				'post_id'    => 0,
				'meta_key'   => '',
				'meta_value' => '',
			),
			$overrides
		);
	}

	/**
	 * Insert a term row, returning its term_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertTerm( PDO $pdo, string $termsTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$termsTable,
			array(
				'name'       => '',
				'slug'       => '',
				'term_group' => 0,
			),
			$overrides
		);
	}

	/**
	 * Insert a term_taxonomy row, returning its term_taxonomy_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertTermTaxonomy( PDO $pdo, string $taxonomyTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$taxonomyTable,
			array(
				'term_id'     => 0,
				'taxonomy'    => 'category',
				'description' => '',
				'parent'      => 0,
				'count'       => 0,
			),
			$overrides
		);
	}

	/**
	 * Insert a term_relationships row.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertTermRelationship( PDO $pdo, string $relationshipsTable, array $overrides = array() ): void {
		self::insert(
			$pdo,
			$relationshipsTable,
			array(
				'object_id'        => 0,
				'term_taxonomy_id' => 0,
				'term_order'       => 0,
			),
			$overrides
		);
	}

	/**
	 * Insert an option row, returning its option_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertOption( PDO $pdo, string $optionsTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$optionsTable,
			array(
				'option_name'  => '',
				'option_value' => '',
				'autoload'     => 'yes',
			),
			$overrides
		);
	}

	/**
	 * Insert a user row, returning its ID.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertUser( PDO $pdo, string $usersTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$usersTable,
			array(
				'user_login'          => '',
				'user_pass'           => '',
				'user_nicename'       => '',
				'user_email'          => '',
				'user_url'            => '',
				'user_registered'     => '2024-01-01 00:00:00',
				'user_activation_key' => '',
				'user_status'         => 0,
				'display_name'        => '',
			),
			$overrides
		);
	}

	/**
	 * Insert a comment row, returning its comment_ID.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertComment( PDO $pdo, string $commentsTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$commentsTable,
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => '',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_date'         => '2024-01-01 00:00:00',
				'comment_date_gmt'     => '2024-01-01 00:00:00',
				'comment_content'      => '',
				'comment_karma'        => 0,
				'comment_approved'     => '1',
				'comment_agent'        => '',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			),
			$overrides
		);
	}

	/**
	 * Insert a commentmeta row, returning its meta_id.
	 *
	 * @param array<string, mixed> $overrides
	 */
	public static function insertCommentMeta( PDO $pdo, string $commentMetaTable, array $overrides = array() ): int {
		return self::insert(
			$pdo,
			$commentMetaTable,
			array(
				'comment_id' => 0,
				'meta_key'   => '',
				'meta_value' => '',
			),
			$overrides
		);
	}

	/**
	 * Network-wide tables: wp_users, wp_usermeta, wp_blogs, wp_sitemeta.
	 */
	private static function networkTables( PDO $pdo, string $prefix ): void {
		$pdo->exec(
			"CREATE TABLE {$prefix}users (
                ID INTEGER PRIMARY KEY,
                user_login TEXT NOT NULL DEFAULT '',
                user_pass TEXT NOT NULL DEFAULT '',
                user_nicename TEXT NOT NULL DEFAULT '',
                user_email TEXT NOT NULL DEFAULT '',
                user_url TEXT NOT NULL DEFAULT '',
                user_registered TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                user_activation_key TEXT NOT NULL DEFAULT '',
                user_status INTEGER NOT NULL DEFAULT 0,
                display_name TEXT NOT NULL DEFAULT ''
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$prefix}usermeta (
                umeta_id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT,
                meta_value TEXT
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$prefix}blogs (
                blog_id INTEGER PRIMARY KEY,
                site_id INTEGER NOT NULL DEFAULT 0,
                domain TEXT NOT NULL DEFAULT '',
                path TEXT NOT NULL DEFAULT '',
                registered TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                last_updated TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                public INTEGER NOT NULL DEFAULT 1,
                archived INTEGER NOT NULL DEFAULT 0,
                mature INTEGER NOT NULL DEFAULT 0,
                spam INTEGER NOT NULL DEFAULT 0,
                deleted INTEGER NOT NULL DEFAULT 0,
                lang_id INTEGER NOT NULL DEFAULT 0
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$prefix}sitemeta (
                meta_id INTEGER PRIMARY KEY,
                site_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT,
                meta_value TEXT
            )"
		);
	}

	/**
	 * Every per-site table for one blog id.
	 */
	private static function siteTables( PDO $pdo, string $prefix, int $blogId ): void {
		$posts = self::siteTableName( $prefix, $blogId, 'posts' );
		$postMeta = self::siteTableName( $prefix, $blogId, 'postmeta' );
		$terms = self::siteTableName( $prefix, $blogId, 'terms' );
		$taxonomy = self::siteTableName( $prefix, $blogId, 'term_taxonomy' );
		$relationships = self::siteTableName( $prefix, $blogId, 'term_relationships' );
		$options = self::siteTableName( $prefix, $blogId, 'options' );
		$comments = self::siteTableName( $prefix, $blogId, 'comments' );
		$commentMeta = self::siteTableName( $prefix, $blogId, 'commentmeta' );

		$pdo->exec(
			"CREATE TABLE {$posts} (
                ID INTEGER PRIMARY KEY,
                post_author INTEGER NOT NULL DEFAULT 0,
                post_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_content TEXT NOT NULL,
                post_title TEXT NOT NULL DEFAULT '',
                post_excerpt TEXT NOT NULL DEFAULT '',
                post_status TEXT NOT NULL DEFAULT 'publish',
                comment_status TEXT NOT NULL DEFAULT 'open',
                ping_status TEXT NOT NULL DEFAULT 'open',
                post_password TEXT NOT NULL DEFAULT '',
                post_name TEXT NOT NULL DEFAULT '',
                to_ping TEXT NOT NULL DEFAULT '',
                pinged TEXT NOT NULL DEFAULT '',
                post_modified TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_modified_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                post_content_filtered TEXT NOT NULL DEFAULT '',
                post_parent INTEGER NOT NULL DEFAULT 0,
                guid TEXT NOT NULL DEFAULT '',
                menu_order INTEGER NOT NULL DEFAULT 0,
                post_type TEXT NOT NULL DEFAULT 'post',
                post_mime_type TEXT NOT NULL DEFAULT '',
                comment_count INTEGER NOT NULL DEFAULT 0
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$postMeta} (
                meta_id INTEGER PRIMARY KEY,
                post_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT,
                meta_value TEXT
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$terms} (
                term_id INTEGER PRIMARY KEY,
                name TEXT NOT NULL DEFAULT '',
                slug TEXT NOT NULL DEFAULT '',
                term_group INTEGER NOT NULL DEFAULT 0
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$taxonomy} (
                term_taxonomy_id INTEGER PRIMARY KEY,
                term_id INTEGER NOT NULL DEFAULT 0,
                taxonomy TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                parent INTEGER NOT NULL DEFAULT 0,
                count INTEGER NOT NULL DEFAULT 0
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$relationships} (
                object_id INTEGER NOT NULL DEFAULT 0,
                term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
                term_order INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (object_id, term_taxonomy_id)
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$options} (
                option_id INTEGER PRIMARY KEY,
                option_name TEXT NOT NULL DEFAULT '',
                option_value TEXT NOT NULL DEFAULT '',
                autoload TEXT NOT NULL DEFAULT 'yes'
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$comments} (
                comment_ID INTEGER PRIMARY KEY,
                comment_post_ID INTEGER NOT NULL DEFAULT 0,
                comment_author TEXT NOT NULL DEFAULT '',
                comment_author_email TEXT NOT NULL DEFAULT '',
                comment_author_url TEXT NOT NULL DEFAULT '',
                comment_author_IP TEXT NOT NULL DEFAULT '',
                comment_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                comment_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
                comment_content TEXT NOT NULL DEFAULT '',
                comment_karma INTEGER NOT NULL DEFAULT 0,
                comment_approved TEXT NOT NULL DEFAULT '1',
                comment_agent TEXT NOT NULL DEFAULT '',
                comment_type TEXT NOT NULL DEFAULT 'comment',
                comment_parent INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0
            )"
		);
		$pdo->exec(
			"CREATE TABLE {$commentMeta} (
                meta_id INTEGER PRIMARY KEY,
                comment_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT,
                meta_value TEXT
            )"
		);
	}

	/**
	 * Build an INSERT from defaults + overrides and return the new ID.
	 *
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $overrides
	 */
	private static function insert( PDO $pdo, string $table, array $defaults, array $overrides ): int {
		$values = array_merge( $defaults, $overrides );
		$columns = array_keys( $values );
		$placeholders = array();
		foreach ( $columns as $column ) {
			$placeholders[] = ':' . $column;
		}

		$statement = $pdo->prepare(
			'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')'
		);
		$params = array();
		foreach ( $values as $column => $value ) {
			$params[ ':' . $column ] = $value;
		}
		$statement->execute( $params );

		return (int) $pdo->lastInsertId();
	}
}
