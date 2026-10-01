<?php

declare(strict_types=1);

namespace MergeMultisite\Migration;

/**
 * Safely parses, recursively walks, rewrites, and re-serializes PHP-serialized
 * and JSON data structures, automatically recalculating string byte-length
 * prefixes (PLAN.md §5).
 *
 * Essential for migrating postmeta, options, comments, and widget configurations
 * where embedded IDs and URLs must be remapped without corrupting serialized byte lengths.
 *
 * Uses `allowed_classes => ['stdClass']` to prevent PHP Object Injection while
 * supporting standard WordPress data structures (like Beaver Builder nodes).
 *
 * @package MergeMultisite
 */
final class SerializedDataRewriter {

	private const MAX_DEPTH = 64;

	/**
	 * Determines whether a string is PHP-serialized.
	 */
	public function isSerialized( string $data ): bool {
		$data = trim( $data );
		if ( $data === '' || $data === 'b:0;' ) {
			return $data === 'b:0;';
		}

		$length = strlen( $data );
		if ( $length < 4 || $data[1] !== ':' ) {
			return false;
		}

		$token = $data[0];
		if ( ! in_array( $token, array( 'a', 'O', 's', 'b', 'i', 'd' ), true ) ) {
			return false;
		}

		// Security: 'allowed_classes' => ['stdClass'] prevents arbitrary class instantiation.
		$unserialized = @unserialize( $data, array( 'allowed_classes' => array( 'stdClass' ) ) );

		return $unserialized !== false;
	}

	/**
	 * Determines whether a string is valid JSON object or array.
	 */
	public function isJson( string $data ): bool {
		$data = trim( $data );
		if ( $data === '' || ( $data[0] !== '{' && $data[0] !== '[' ) ) {
			return false;
		}

		try {
			json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
			return true;
		} catch ( \JsonException ) {
			return false;
		}
	}

	/**
	 * Rewrites a serialized string by safely unserializing, recursively walking
	 * all values through $replacer, and re-serializing.
	 *
	 * If the string is not valid serialized data, it is returned unchanged.
	 *
	 * @param callable $replacer Callback fn(mixed $value, string|int $key, array $path): mixed.
	 */
	public function rewrite( string $serialized, callable $replacer ): string {
		if ( ! $this->isSerialized( $serialized ) ) {
			return $serialized;
		}

		$unserialized = @unserialize( $serialized, array( 'allowed_classes' => array( 'stdClass' ) ) );
		if ( $unserialized === false && $serialized !== 'b:0;' ) {
			return $serialized;
		}

		$rewritten = $this->walk( $unserialized, $replacer, array(), 0 );

		return serialize( $rewritten );
	}

	/**
	 * Rewrites string values inside serialized data (e.g. updating old URLs or paths).
	 *
	 * @param callable $stringReplacer Callback fn(string $value, string|int $key, array $path): string.
	 */
	public function rewriteStrings( string $serialized, callable $stringReplacer ): string {
		return $this->rewrite(
			$serialized,
			static function ( mixed $value, string|int $key, array $path ) use ( $stringReplacer ): mixed {
				return is_string( $value ) ? $stringReplacer( $value, $key, $path ) : $value;
			}
		);
	}

	/**
	 * Rewrites integer values inside serialized data (e.g. remapping attachment IDs).
	 *
	 * @param callable $intReplacer Callback fn(int $value, string|int $key, array $path): int.
	 */
	public function rewriteIntegers( string $serialized, callable $intReplacer ): string {
		return $this->rewrite(
			$serialized,
			static function ( mixed $value, string|int $key, array $path ) use ( $intReplacer ): mixed {
				return is_int( $value ) ? $intReplacer( $value, $key, $path ) : $value;
			}
		);
	}

	/**
	 * Rewrites a JSON string by decoding, recursively walking all values through
	 * $replacer, and re-encoding with JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES.
	 *
	 * @param callable $replacer Callback fn(mixed $value, string|int $key, array $path): mixed.
	 */
	public function rewriteJson( string $json, callable $replacer ): string {
		if ( ! $this->isJson( $json ) ) {
			return $json;
		}

		try {
			$decoded   = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
			$rewritten = $this->walk( $decoded, $replacer, array(), 0 );

			return (string) json_encode( $rewritten, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} catch ( \JsonException ) {
			return $json;
		}
	}

	/**
	 * Automatically detects format (serialized, JSON, or plain string) and rewrites accordingly.
	 *
	 * @param callable $replacer Callback fn(mixed $value, string|int $key, array $path): mixed.
	 */
	public function rewriteAny( string $data, callable $replacer ): string {
		if ( $this->isSerialized( $data ) ) {
			return $this->rewrite( $data, $replacer );
		}

		if ( $this->isJson( $data ) ) {
			return $this->rewriteJson( $data, $replacer );
		}

		$result = $replacer( $data, '', array() );

		return is_string( $result ) ? $result : $data;
	}

	/**
	 * Recursively walks an array or stdClass object and invokes $replacer on each node.
	 *
	 * @param array<int, string|int> $path
	 */
	private function walk( mixed $data, callable $replacer, array $path, int $depth ): mixed {
		if ( $depth >= self::MAX_DEPTH ) {
			return $data;
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$currentPath    = array( ...$path, $key );
				$walkedValue    = $this->walk( $value, $replacer, $currentPath, $depth + 1 );
				$result[ $key ] = $replacer( $walkedValue, $key, $currentPath );
			}
			return $result;
		}

		if ( $data instanceof \stdClass ) {
			$vars = get_object_vars( $data );
			foreach ( $vars as $key => $value ) {
				$currentPath    = array( ...$path, $key );
				$walkedValue    = $this->walk( $value, $replacer, $currentPath, $depth + 1 );
				$data->$key     = $replacer( $walkedValue, $key, $currentPath );
			}
			return $data;
		}

		return $data;
	}
}
