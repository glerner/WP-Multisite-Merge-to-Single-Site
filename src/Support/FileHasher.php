<?php

declare(strict_types=1);

namespace MergeMultisite\Support;

/**
 * Computes the size+hash fingerprint used throughout this project to
 * decide whether two files are "the same" for media de-duplication
 * purposes (see PLAN.md §7.2).
 *
 * @package MergeMultisite
 */
final class FileHasher {

	/**
	 * Build a fingerprint for a file on disk: its size in bytes plus a
	 * SHA-256 hash of its contents. Two files are considered identical
	 * only when both match -- comparing size first is a cheap way to
	 * rule out most non-matches before hashing the full file contents.
	 *
	 * @throws \RuntimeException If the file cannot be read.
	 */
	public function fingerprint( string $path ): FileFingerprint {
		$size = filesize( $path );
		if ( $size === false ) {
			throw new \RuntimeException( sprintf( 'Could not stat file "%s".', $path ) );
		}

		$hash = hash_file( 'sha256', $path );
		if ( $hash === false ) {
			throw new \RuntimeException( sprintf( 'Could not hash file "%s".', $path ) );
		}

		return new FileFingerprint( $size, $hash );
	}

	/**
	 * Convenience check: do two files on disk have identical content?
	 */
	public function areIdentical( string $pathA, string $pathB ): bool {
		return $this->fingerprint( $pathA )->equals( $this->fingerprint( $pathB ) );
	}
}
