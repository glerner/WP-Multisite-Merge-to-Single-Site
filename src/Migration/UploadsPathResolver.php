<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Resolves the absolute filesystem path of a subsite's uploaded file,
 * given the network's base uploads directory and the relative path
 * stored in `_wp_attached_file` postmeta.
 *
 * Handles both the modern multisite layout
 * (`wp-content/uploads/sites/{blog_id}/YYYY/MM/file.ext`, and the bare
 * `wp-content/uploads/YYYY/MM/file.ext` for the network's main site)
 * and, as a defensive fallback, the legacy pre-3.5 layout
 * (`wp-content/blogs.dir/{blog_id}/files/YYYY/MM/file.ext`) -- see
 * PLAN.md §7.2.
 *
 * @package MergeMultisite
 */
final class UploadsPathResolver {

	public function __construct( private readonly string $baseUploadsPath ) {
	}

	/**
	 * Return every candidate absolute path for a given site + relative
	 * file, in the order they should be checked. The first one that
	 * exists on disk is the real location.
	 *
	 * @return string[]
	 */
	public function candidatePaths( int $blogId, string $relativeFile ): array {
		$relativeFile = ltrim( $relativeFile, '/' );
		$base = rtrim( $this->baseUploadsPath, '/' );

		if ( $blogId <= 1 ) {
			return array( $base . '/' . $relativeFile );
		}

		return array(
			// Modern layout.
			$base . '/sites/' . $blogId . '/' . $relativeFile,
			// Legacy WPMU layout (pre-3.5), kept as a fallback.
			dirname( $base ) . '/blogs.dir/' . $blogId . '/files/' . $relativeFile,
		);
	}

	/**
	 * Resolve the real, existing absolute path for a site + relative
	 * file, or null if none of the candidate locations exist.
	 */
	public function resolve( int $blogId, string $relativeFile ): ?string {
		foreach ( $this->candidatePaths( $blogId, $relativeFile ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * The directory that actually holds a site's uploaded files on
	 * disk (as opposed to candidatePaths(), which is per-file). Used
	 * by checks that need to walk everything present on disk rather
	 * than resolve one known attachment -- e.g. finding files that
	 * exist on disk but have no matching attachment in the database.
	 *
	 * Returns null if none of the candidate directories exist at all.
	 */
	public function siteRootPath( int $blogId ): ?string {
		$base = rtrim( $this->baseUploadsPath, '/' );

		$candidates = $blogId <= 1
			? array( $base )
			: array(
				$base . '/sites/' . $blogId,
				dirname( $base ) . '/blogs.dir/' . $blogId . '/files',
			);

		foreach ( $candidates as $candidate ) {
			if ( is_dir( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
