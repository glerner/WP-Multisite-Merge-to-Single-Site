<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Shared contact-page slug detection + canonicalization mapping
 * (PLAN.md §7.1).
 *
 * Multisite networks accumulate contact-page slug variants
 * (`contact`, `contact-us`, `contact-me`, `get-in-touch`, ...) which
 * the migration canonicalizes to `/contact/`. The detection pattern
 * (any slug containing "contact") and the variant→canonical mapping
 * are shared by ContactPageDiscoveryCheck (audit: confirms the
 * `contact_page_paths` config list is complete) and PostMigrator /
 * UrlRewriter (which rewrite the URLs and feed the redirect map).
 *
 * @package MergeMultisite
 */
final class ContactPageCanonicalizer {

	/**
	 * The slug every configured variant canonicalizes to.
	 */
	public const CANONICAL_SLUG = 'contact';

	/**
	 * The detection pattern: any slug containing "contact" is a
	 * candidate variant. PHP equivalent of the SQL pre-filter
	 * `post_name LIKE '%contact%'` used to bound the candidate set.
	 */
	public static function isContactLike( string $slug ): bool {
		return str_contains( strtolower( $slug ), 'contact' );
	}

	/**
	 * The SQL LIKE fragment implementing the same detection pattern,
	 * for queries that pre-filter candidates in the database.
	 */
	public static function candidateClause( string $column = 'post_name' ): string {
		return $column . " LIKE '%contact%'";
	}

	/**
	 * Normalize a slug or path for comparison against the configured
	 * `contact_page_paths` list: trims slashes and lowercases so
	 * `contact-me`, `/contact-me/`, and `/Contact-Me/` all match.
	 */
	public static function normalizePath( string $path ): string {
		return strtolower( trim( $path, '/' ) );
	}

	/**
	 * Whether a slug/path is in the configured variant list — i.e.
	 * scheduled for canonicalization to `/contact/`.
	 *
	 * @param string[] $contactPagePaths
	 */
	public static function isVariant( string $path, array $contactPagePaths ): bool {
		$normalized = self::normalizePath( $path );

		foreach ( $contactPagePaths as $variant ) {
			if ( self::normalizePath( $variant ) === $normalized ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The canonical destination path for a configured variant:
	 * `/contact/` when the path is a known variant, null when it
	 * isn't (left untouched by the migration).
	 *
	 * @param string[] $contactPagePaths
	 */
	public static function canonicalPath( string $path, array $contactPagePaths ): ?string {
		return self::isVariant( $path, $contactPagePaths )
			? '/' . self::CANONICAL_SLUG . '/'
			: null;
	}
}
