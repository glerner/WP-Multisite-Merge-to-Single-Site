<?php

declare(strict_types=1);

namespace MergeMultisite\Audit;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\Site;
use MergeMultisite\Support\Logger;

/**
 * Runs a set of AuditCheckInterface implementations against the source
 * database and collects their findings.
 *
 * @package MergeMultisite
 */
final class AuditRunner {

	/**
	 * @param AuditCheckInterface[] $checks
	 */
	public function __construct(
		private readonly array $checks,
		private readonly Logger $logger,
	) {
	}

	/**
	 * @param Site[] $sites
	 *
	 * @return AuditFinding[]
	 */
	public function run( Connection $source, MergeConfig $config, array $sites ): array {
		$findings = array();

		foreach ( $this->checks as $check ) {
			$this->logger->info( sprintf( 'Running check: %s', $check->name() ) );

			try {
				$checkFindings = $check->run( $source, $config, $sites );
			} catch ( \Throwable $exception ) {
				$this->logger->error( sprintf( 'Check "%s" failed: %s', $check->name(), $exception->getMessage() ) );
				$findings[] = AuditFinding::error(
					$check->name(),
					sprintf( 'Check crashed: %s', $exception->getMessage() )
				);
				continue;
			}

			$this->logger->info( sprintf( '  -> %d finding(s)', count( $checkFindings ) ) );
			$findings = array( ...$findings, ...$checkFindings );
		}

		return $findings;
	}
}
