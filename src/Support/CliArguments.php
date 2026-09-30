<?php

declare(strict_types=1);

namespace MergeMultisite\Support;

/**
 * Minimal CLI argument parser supporting `--flag`, `--key=value`, and
 * `--key value` forms. Deliberately dependency-free.
 *
 * @package MergeMultisite
 */
final class CliArguments {

	/** @var array<string, string|bool> */
	private readonly array $options;

	/** @var string[] */
	private readonly array $positional;

	/**
	 * @param string[] $argv         Typically $argv from the invoking script, including argv[0].
	 * @param string[] $booleanFlags Option names that are always boolean flags and never consume the next argument.
	 */
	public function __construct( array $argv, array $booleanFlags = array() ) {
		$options    = array();
		$positional = array();
		$args       = array_slice( $argv, 1 );

		for ( $i = 0, $count = count( $args ); $i < $count; $i++ ) {
			$arg = $args[ $i ];

			if ( ! str_starts_with( $arg, '--' ) ) {
				$positional[] = $arg;
				continue;
			}

			$body = substr( $arg, 2 );

			if ( str_contains( $body, '=' ) ) {
				[$key, $value]   = explode( '=', $body, 2 );
				$options[ $key ] = $value;
				continue;
			}

			if ( in_array( $body, $booleanFlags, true ) ) {
				$options[ $body ] = true;
				continue;
			}

			$next = $args[ $i + 1 ] ?? null;
			if ( $next !== null && ! str_starts_with( $next, '--' ) ) {
				$options[ $body ] = $next;
				$i++;
				continue;
			}

			$options[ $body ] = true;
		}

		$this->options    = $options;
		$this->positional = $positional;
	}

	/**
	 * Returns all non-option positional arguments in the order they appeared.
	 *
	 * @return string[]
	 */
	public function positional(): array {
		return $this->positional;
	}

	public function has( string $key ): bool {
		return array_key_exists( $key, $this->options );
	}

	public function get( string $key, ?string $default = null ): ?string {
		$value = $this->options[ $key ] ?? null;

		if ( $value === null ) {
			return $default;
		}

		return is_bool( $value ) ? (string) (int) $value : $value;
	}

	public function getInt( string $key, ?int $default = null ): ?int {
		$value = $this->get( $key );

		return $value === null ? $default : (int) $value;
	}
}
