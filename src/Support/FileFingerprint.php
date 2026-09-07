<?php

declare(strict_types=1);

namespace MergeMultisite\Support;

/**
 * Value object holding a file's size + content hash, used to compare
 * two files for equality without assuming filename has any bearing on
 * content.
 *
 * @package MergeMultisite
 */
final class FileFingerprint {

	/**
	 * @param int    $size Size in bytes.
	 * @param string $hash SHA-256 hex digest of the file's contents.
	 */
	public function __construct(
		public readonly int $size,
		public readonly string $hash,
	) {
	}

	public function equals( FileFingerprint $other ): bool {
		return $this->size === $other->size && $this->hash === $other->hash;
	}

	public function toKey(): string {
		return $this->size . ':' . $this->hash;
	}
}
