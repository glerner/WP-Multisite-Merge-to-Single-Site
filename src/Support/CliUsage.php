<?php

declare(strict_types=1);

namespace MergeMultisite\Support;

/**
 * Usage output for the bin/*.php entry points, sourced from each
 * script's own file-level docblock so the text has a single source of
 * truth and can never drift away from a duplicated heredoc.
 *
 * Function printFileDocblock() backs --help (the whole docblock).
 * printUsage() runs on every invocation: just the "Usage:" section's
 * command lines, so the accepted flags are always on screen.
 *
 * @package MergeMultisite
 */
final class CliUsage {

	public static function printFileDocblock( string $file ): void {
		$text = self::docblockText( $file );
		if ( $text === null ) {
			fwrite( STDERR, sprintf( "No readable docblock in %s.\n", $file ) );
			return;
		}

		fwrite( STDOUT, $text );
	}

	public static function printUsage( string $file ): void {
		$text = self::docblockText( $file );
		if ( $text === null ) {
			return;
		}

		// Collect the "php ..." command lines inside the "Usage:"
		// section. The section ends at a blank line or a new heading
		// (a line ending in ":"); indented description lines in between
		// are skipped, not terminators.
		$inUsage  = false;
		$commands = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			if ( ! $inUsage ) {
				$inUsage = preg_match( '/^\s*Usage:\s*$/i', $line ) === 1;
				continue;
			}
			if ( trim( $line ) === '' || preg_match( '/^\s*[A-Z][A-Za-z ]*:\s*$/', $line ) === 1 ) {
				break;
			}
			if ( preg_match( '/^\s*php\s/', $line ) === 1 ) {
				$commands[] = trim( $line );
			}
		}

		if ( $commands !== array() ) {
			fwrite( STDOUT, "Usage:\n  " . implode( "\n  ", $commands ) . "\n" );
		}
	}

	/**
	 * Returns the file's first docblock with its leading " * " markers
	 * stripped, or null when the file/docblock cannot be read.
	 */
	private static function docblockText( string $file ): ?string {
		$source = file_get_contents( $file );
		if ( $source === false ) {
			return null;
		}

		if ( preg_match( '/\/\*\*(?<doc>.*?)\*\//s', $source, $matches ) !== 1 ) {
			return null;
		}

		$text = (string) preg_replace( '/^[ \t]*\*[ \t]?/m', '', (string) $matches['doc'] );
		// Docblock annotations like "@package" are metadata, not help text.
		$text = (string) preg_replace( '/^\s*@\w+.*\R?/m', '', $text );

		return trim( $text ) . PHP_EOL;
	}
}
