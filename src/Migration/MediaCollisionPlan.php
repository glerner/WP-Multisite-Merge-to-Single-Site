<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Pure, DB-free collision/dedup planning for media files (PLAN.md §7.2).
 *
 * On the destination every source file lands at its `_wp_attached_file`
 * relative path (`YYYY/MM/name.ext`) because the `sites/{blog_id}/`
 * prefix is dropped. Files sharing that destination path:
 *
 *  - identical content (size + SHA-256): dedup to a single file;
 *  - different content: EVERY file in the group is renamed
 *    `{basename}_site{blog_id}.{ext}` so each name stays traceable to
 *    its source site rather than an arbitrary `-2`/`-3` counter.
 *
 * Shared by MediaFileCheck (reporting) and MediaMigrator (copy +
 * `_wp_attached_file`/`_wp_attachment_metadata` rewriting).
 *
 * @package MergeMultisite
 */
final class MediaCollisionPlan {

	/**
	 * Resolve the destination-relative path for every source file.
	 *
	 * @param array<int, array{blog_id:int, relative:string}> $files
	 * @param array<int, string>                              $fingerprints Index-aligned with $files.
	 *
	 * @return array<int, array{target:string, renamed:bool}> Index-aligned
	 *         with $files; `target` is the destination-relative path and
	 *         `renamed` marks entries that got the `_site{blog_id}` suffix.
	 */
	public function resolve( array $files, array $fingerprints ): array {
		$byPath = array();
		foreach ( $files as $index => $file ) {
			$byPath[ $file['relative'] ][ $index ] = $fingerprints[ $index ];
		}

		$targets = array();
		foreach ( $byPath as $relative => $group ) {
			$collision = count( array_unique( $group ) ) > 1;
			foreach ( $group as $index => $fingerprint ) {
				$targets[ $index ] = array(
					'target' => $collision
						? self::renamedRelativePath( $relative, $files[ $index ]['blog_id'] )
						: $relative,
					'renamed' => $collision,
				);
			}
		}

		return $targets;
	}

	/**
	 * Group files by basename for collision reporting — a wider net
	 * than resolve()'s exact-path grouping, since the same filename in
	 * different directories is still worth listing together.
	 *
	 * @param array<int, array{path:string}> $files
	 *
	 * @return array<string, array<int, array>> basename => index => file, sorted by basename.
	 */
	public function groupsByBasename( array $files ): array {
		$groups = array();
		foreach ( $files as $index => $file ) {
			$groups[ basename( $file['path'] ) ][ $index ] = $file;
		}

		ksort( $groups );

		return $groups;
	}

	/**
	 * Group files by content fingerprint regardless of filename —
	 * finds duplicate Media Library uploads under different names.
	 *
	 * @param array<int, array>  $files
	 * @param array<int, string> $fingerprints Index-aligned with $files.
	 *
	 * @return array<string, array<int, array>> fingerprint => index => file.
	 */
	public function groupsByFingerprint( array $files, array $fingerprints ): array {
		$groups = array();
		foreach ( $files as $index => $file ) {
			$groups[ $fingerprints[ $index ] ][ $index ] = $file;
		}

		return $groups;
	}

	/**
	 * The colliding-file rename target for a relative path:
	 * `2024/01/logo.png` → `2024/01/logo_site7.png` (PLAN.md §7.2).
	 */
	public static function renamedRelativePath( string $relative, int $blogId ): string {
		$dir = dirname( $relative );
		$prefix = $dir === '.' ? '' : $dir . '/';

		return $prefix . self::renamedBasename( basename( $relative ), $blogId );
	}

	/**
	 * The rename for a generated thumbnail-size filename, keeping
	 * WordPress's `-{W}x{H}` suffix after the site marker so it lines
	 * up with the renamed base the way `-150x150` does on the original
	 * name: `logo-150x150.png` → `logo_site7-150x150.png`.
	 */
	public static function renamedVariantFilename( string $filename, int $blogId ): string {
		if ( preg_match( '/^(.*)-(\d+x\d+)(\.[^.]+)$/', $filename, $matches ) === 1 ) {
			return $matches[1] . '_site' . $blogId . '-' . $matches[2] . $matches[3];
		}

		return self::renamedBasename( $filename, $blogId );
	}

	/**
	 * `logo.png` → `logo_site7.png`; extensionless names get the
	 * suffix appended whole (`README` → `README_site7`).
	 */
	private static function renamedBasename( string $basename, int $blogId ): string {
		$extension = pathinfo( $basename, PATHINFO_EXTENSION );
		$name = $extension === ''
			? $basename
			: substr( $basename, 0, -( strlen( $extension ) + 1 ) );

		return $name . '_site' . $blogId . ( $extension === '' ? '' : '.' . $extension );
	}
}
