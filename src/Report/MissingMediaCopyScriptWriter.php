<?php

declare(strict_types=1);

namespace MergeMultisite\Report;

use FilesystemIterator;
use MergeMultisite\Audit\AuditFinding;
use MergeMultisite\Migration\UploadsPathResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * For every "media-files.missing-file" finding, searches the
 * directories configured in config.php's "media_search_paths" (old
 * site backups, the legacy blogs.dir tree, etc.) for the file by
 * basename, and emits a bash script of `install -D` commands that copy
 * each recovered file into the SOURCE multisite uploads tree -- i.e.
 * where the migrator expects to find it. Copying into the source (not
 * the destination) is deliberate: the migrator's own collision-rename
 * logic then still applies.
 *
 * @package MergeMultisite
 */
final class MissingMediaCopyScriptWriter {

	/**
	 * @param AuditFinding[] $findings    Findings from the audit run;
	 *                                    only "media-files.missing-file"
	 *                                    entries are used.
	 * @param string[]       $searchPaths Directories searched recursively
	 *                                    for each missing file's basename.
	 *
	 * @return string|null Script contents, or null when there are no
	 *                     missing files (or no search paths to look in).
	 */
	public function generate( array $findings, array $searchPaths, UploadsPathResolver $resolver ): ?string {
		if ( $searchPaths === array() ) {
			return null;
		}

		$missing = array();
		foreach ( $findings as $finding ) {
			if ( $finding->checkName !== 'media-files.missing-file' ) {
				continue;
			}
			$relative = (string) ( $finding->context['relative_file'] ?? '' );
			foreach ( $finding->context['attachments'] ?? array() as $attachment ) {
				if ( $relative === '' || ! isset( $attachment['blog_id'] ) ) {
					continue;
				}
				$missing[ (int) $attachment['blog_id'] . '|' . $relative ] = array(
					'blog_id'  => (int) $attachment['blog_id'],
					'post_id'  => (int) ( $attachment['post_id'] ?? 0 ),
					'relative' => $relative,
				);
			}
		}

		if ( $missing === array() ) {
			return null;
		}

		$index = $this->indexByBasename( $searchPaths );

		$lines = array(
			'#!/usr/bin/env bash',
			'set -euo pipefail',
			'# Copies recovered media into the SOURCE multisite uploads tree,',
			'# where the migrator expects to find the files. The migration',
			'# then applies its own collision-renaming to the destination.',
			'',
		);

		ksort( $missing );
		foreach ( $missing as $entry ) {
			$target = $resolver->candidatePaths( $entry['blog_id'], $entry['relative'] )[0];
			$found = $this->locate( $index, $entry['relative'] );

			if ( $found === null ) {
				$lines[] = sprintf(
					'# NOT FOUND: site %d, attachment %d: %s',
					$entry['blog_id'],
					$entry['post_id'],
					$entry['relative']
				);
				continue;
			}

			$lines[] = sprintf(
				'# site %d, attachment %d: %s',
				$entry['blog_id'],
				$entry['post_id'],
				$entry['relative']
			);
			$lines[] = sprintf(
				'install -D -m 0644 %s %s',
				self::shellQuote( $found ),
				self::shellQuote( $target )
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Walk every search path once, indexing files by basename so each
	 * missing file is an O(1) lookup rather than a fresh `find`.
	 *
	 * @param string[] $searchPaths
	 *
	 * @return array<string, string[]> basename => absolute paths
	 */
	private function indexByBasename( array $searchPaths ): array {
		$index = array();

		foreach ( $searchPaths as $root ) {
			$root = rtrim( $root, '/' );
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $fileInfo ) {
				if ( $fileInfo->isFile() ) {
					$index[ $fileInfo->getBasename() ][] = $fileInfo->getPathname();
				}
			}
		}

		return $index;
	}

	/**
	 * @param array<string, string[]> $index
	 */
	private function locate( array $index, string $relative ): ?string {
		$candidates = $index[ basename( $relative ) ] ?? array();

		// Prefer a candidate whose path ends with the full relative path
		// (e.g. blogs.dir/60/files/2013/08/x.png for 2013/08/x.png) --
		// disambiguates same-named files living in different folders.
		foreach ( $candidates as $candidate ) {
			if ( str_ends_with( str_replace( '\\', '/', $candidate ), $relative ) ) {
				return $candidate;
			}
		}

		return $candidates[0] ?? null;
	}

	private static function shellQuote( string $arg ): string {
		return "'" . str_replace( "'", "'\\''", $arg ) . "'";
	}
}
