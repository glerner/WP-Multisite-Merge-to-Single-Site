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

	/**
	 * @param string[] $argv Typically $argv from the invoking script, including argv[0].
	 */
	public function __construct( array $argv ) {
		$options = array();
		$args = array_slice( $argv, 1 );

		for ( $i = 0, $count = count( $args ); $i < $count; $i++ ) {
			$arg = $args[ $i ];

			if ( ! str_starts_with( $arg, '--' ) ) {
				continue;
			}

			$body = substr( $arg, 2 );

			if ( str_contains( $body, '=' ) ) {
				[$key, $value] = explode( '=', $body, 2 );
				$options[ $key ] = $value;
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

		$this->options = $options;
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
