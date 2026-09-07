<?php

declare(strict_types=1);

namespace MergeMultisite\Audit;

/**
 * One issue (or informational note) discovered by an audit check.
 *
 * @package MergeMultisite
 */
final class AuditFinding {

	public const SEVERITY_ERROR = 'error';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_INFO = 'info';

	/**
	 * @param string               $checkName Name of the check that produced this finding.
	 * @param string               $severity  One of the SEVERITY_* constants.
	 * @param string               $message   Human-readable summary.
	 * @param array<string, mixed> $context   Structured detail (site IDs, post IDs, file paths, etc.).
	 */
	public function __construct(
		public readonly string $checkName,
		public readonly string $severity,
		public readonly string $message,
		public readonly array $context = array(),
	) {
	}

	public static function error( string $checkName, string $message, array $context = array() ): self {
		return new self( $checkName, self::SEVERITY_ERROR, $message, $context );
	}

	public static function warning( string $checkName, string $message, array $context = array() ): self {
		return new self( $checkName, self::SEVERITY_WARNING, $message, $context );
	}

	public static function info( string $checkName, string $message, array $context = array() ): self {
		return new self( $checkName, self::SEVERITY_INFO, $message, $context );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'check' => $this->checkName,
			'severity' => $this->severity,
			'message' => $this->message,
			'context' => $this->context,
		);
	}
}
