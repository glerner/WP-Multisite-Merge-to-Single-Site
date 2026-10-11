<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Pure filename hygiene for media files: detects characters that are
 * problematic in URLs, browsers, or Windows filesystems, and suggests
 * a safe, human-readable replacement name (PLAN.md §7.2).
 *
 * Suggestions are heuristic and deterministic -- the point is to feed
 * the report's "media-filenames" tab so a human (or a media-rename
 * plugin) can apply them; the migrator never renames on its own.
 *
 * The suggestion pipeline:
 *  1. Strip machine noise from the basename: leading upload dates and
 *     timestamps (2024-12-19-22.39.42), WordPress's `-e{unix_ms}`
 *     suffix, and trailing `-scaled`/`-rotated` markers.
 *  2. If nothing descriptive survives (generic names like
 *     "image-2024", "img_1234", "screenshot"), fall back to the
 *     attachment's alt text, then caption, then title -- whichever is
 *     the first non-generic label available (e.g. a DALL·E filename
 *     whose alt text says "Futuristic abstract design").
 *  3. Sanitize: lowercase, replace anything outside [a-z0-9._-] with
 *     '-', collapse runs, drop leading/trailing separators, and cap
 *     the length at a word boundary.
 *
 * @package MergeMultisite
 */
final class MediaFilenameSuggester {

	/**
	 * Suggested basenames are capped at this many characters,
	 * truncating at the last '-' within the limit so words are never
	 * split (long filenames are the original problem; don't suggest
	 * another one).
	 */
	private const MAX_BASENAME_LENGTH = 90;

	/**
	 * Basenames that carry no descriptive content after noise
	 * stripping ("img", "image-2024", "photo 1", "wp-1600", ...) --
	 * these fall through to alt/caption/title for a better name.
	 */
	private const GENERIC_BASENAME_PATTERN = '/^(?:img|image|photo|picture|screenshot|screen|shot|untitled|scaled|full|wp|file|media|scan|scan[0-9]+|p[0-9]+)?[-_. ]?\d*$/i';

	/**
	 * What is wrong with a media filename, as
	 * array{type: string, detail: string} entries.
	 *
	 * @return array<int, array{type: string, detail: string}>
	 */
	public function problems( string $filename ): array {
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		$problems = array();

		if ( (bool) preg_match( '/[^\x00-\x7F]/u', $basename ) ) {
			preg_match_all( '/[^\x00-\x7F]/u', $basename, $matches );
			$nonAscii = array_values( array_unique( $matches[0] ) );
			$problems[] = array(
				'type' => 'non-ascii',
				'detail' => sprintf( 'non-ASCII character(s): %s', implode( ' ', array_slice( $nonAscii, 0, 8 ) ) . ( count( $nonAscii ) > 8 ? ' …' : '' ) ),
			);
		}

		if ( (bool) preg_match( '/[<>:"\/\\\\|?*]/', $basename ) ) {
			$problems[] = array(
				'type' => 'windows-illegal',
				'detail' => 'contains character(s) illegal on Windows: < > : " / \\ | ? *',
			);
		}

		if ( str_contains( $basename, ' ' ) ) {
			$problems[] = array(
				'type' => 'space',
				'detail' => 'contains space(s); WordPress replaces these with dashes on upload',
			);
		}

		if ( (bool) preg_match( '/[\x00-\x1F\x7F]/', $basename ) ) {
			$problems[] = array(
				'type' => 'control',
				'detail' => 'contains control character(s)',
			);
		}

		if ( (bool) preg_match( '/[. ]$/', $basename ) ) {
			$problems[] = array(
				'type' => 'trailing-dot-space',
				'detail' => 'ends in "." or space; Windows silently strips these, breaking the filename',
			);
		}

		if ( (bool) preg_match( '/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $basename ) ) {
			$problems[] = array(
				'type' => 'reserved-name',
				'detail' => 'is a reserved Windows device name (CON, PRN, AUX, NUL, COM1-9, LPT1-9)',
			);
		}

		if ( mb_strlen( $basename ) > 150 ) {
			$problems[] = array(
				'type' => 'too-long',
				'detail' => sprintf( 'basename is %d characters long (long URLs break in some browsers/email and risk Windows path limits)', mb_strlen( $basename ) ),
			);
		}

		return $problems;
	}

	/**
	 * Suggest a safe replacement basename (with extension) for a media
	 * file, or the original name when it is already clean.
	 *
	 * @return array{basename: string, from: string, changed: bool}
	 *         `from` is 'filename', 'alt', 'caption', or 'title' --
	 *         which source supplied the descriptive text.
	 */
	public function suggest( string $filename, string $alt, string $caption, string $title ): array {
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$base = (string) pathinfo( $filename, PATHINFO_FILENAME );

		$descriptive = $this->stripNoise( $base );
		$from = 'filename';

		if ( $this->isGeneric( $descriptive ) ) {
			foreach ( array( $alt, $caption, $title ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( $candidate === '' ) {
					continue;
				}
				$candidateBase = $this->stripNoise( $candidate );
				if ( $this->isGeneric( $candidateBase ) ) {
					continue;
				}
				$descriptive = $candidateBase;
				$from = $alt === $candidate ? 'alt' : ( $caption === $candidate ? 'caption' : 'title' );
				break;
			}
		}

		$sanitized = $this->sanitize( $descriptive );
		$suggested = $extension !== '' ? $sanitized . '.' . $extension : $sanitized;

		return array(
			'basename' => $suggested,
			'from' => $from,
			'changed' => $sanitized !== $base,
		);
	}

	/**
	 * Remove machine-generated noise from a name: upload
	 * date/timestamp (2024-12-19-22.39.42 -- often mid-name, e.g.
	 * "DALL·E-2024-12-19-22.39.42-..."), WordPress's `-e{unix_ms}`
	 * suffix, and trailing `-scaled`/`-rotated` markers.
	 */
	private function stripNoise( string $text ): string {
		$text = (string) preg_replace( '/(?:^|[-_ ])\d{4}[-_]?\d{2}[-_]?\d{2}(?:[-_]\d{2}[.:]\d{2}[.:]\d{2})?/', '', $text );
		$text = (string) preg_replace( '/-e\d{6,}$/', '', $text );
		$text = (string) preg_replace( '/(-scaled|-rotated)$/i', '', $text );

		return trim( $text );
	}

	/**
	 * Whether a (noise-stripped) name carries no descriptive content.
	 */
	private function isGeneric( string $text ): bool {
		return $text === '' || (bool) preg_match( self::GENERIC_BASENAME_PATTERN, $text );
	}

	/**
	 * Lowercase, replace anything outside [a-z0-9._-] with '-', collapse
	 * runs, trim separators, and cap at a word boundary.
	 */
	private function sanitize( string $text ): string {
		$text = strtolower( $text );
		$text = (string) preg_replace( '/[^a-z0-9._-]+/', '-', $text );
		$text = (string) preg_replace( '/-+/', '-', $text );
		$text = trim( $text, '-_.' );

		if ( mb_strlen( $text ) <= self::MAX_BASENAME_LENGTH ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, self::MAX_BASENAME_LENGTH );
		$cut = (string) preg_replace( '/-[^-]*$/', '', $cut );

		return trim( $cut, '-' );
	}
}
