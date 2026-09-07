<?php

declare(strict_types=1);

namespace MergeMultisite\Support;

/**
 * A minimal leveled logger that writes to STDOUT/STDERR and, optionally,
 * a timestamped log file under `var/logs/`.
 *
 * Deliberately does not depend on any external logging package (this
 * project's dependency footprint is kept small and self-contained).
 *
 * @package MergeMultisite
 */
final class Logger {

	public const LEVEL_DEBUG = 0;
	public const LEVEL_INFO = 1;
	public const LEVEL_WARNING = 2;
	public const LEVEL_ERROR = 3;

	/** @var array<string, int> */
	private const LEVEL_MAP = array(
		'debug' => self::LEVEL_DEBUG,
		'info' => self::LEVEL_INFO,
		'warning' => self::LEVEL_WARNING,
		'error' => self::LEVEL_ERROR,
	);

	private readonly int $minLevel;

	/** @var resource|null */
	private $fileHandle = null;

	/**
	 * @param string      $minLevel One of 'debug', 'info', 'warning', 'error'.
	 * @param string|null $logFile  Optional path to also append log lines to.
	 */
	public function __construct( string $minLevel = 'info', ?string $logFile = null ) {
		$this->minLevel = self::LEVEL_MAP[ $minLevel ] ?? self::LEVEL_INFO;

		if ( $logFile !== null ) {
			$directory = dirname( $logFile );
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0775, true );
			}

			$handle = fopen( $logFile, 'a' );
			$this->fileHandle = $handle === false ? null : $handle;
		}
	}

	public function debug( string $message ): void {
		$this->log( self::LEVEL_DEBUG, 'DEBUG', $message );
	}

	public function info( string $message ): void {
		$this->log( self::LEVEL_INFO, 'INFO', $message );
	}

	public function warning( string $message ): void {
		$this->log( self::LEVEL_WARNING, 'WARNING', $message );
	}

	public function error( string $message ): void {
		$this->log( self::LEVEL_ERROR, 'ERROR', $message );
	}

	private function log( int $level, string $label, string $message ): void {
		if ( $level < $this->minLevel ) {
			return;
		}

		$line = sprintf( '[%s] %s: %s', date( 'Y-m-d H:i:s' ), $label, $message );

		fwrite( $level >= self::LEVEL_WARNING ? STDERR : STDOUT, $line . PHP_EOL );

		if ( $this->fileHandle !== null ) {
			fwrite( $this->fileHandle, $line . PHP_EOL );
		}
	}

	public function __destruct() {
		if ( $this->fileHandle !== null ) {
			fclose( $this->fileHandle );
		}
	}
}
