<?php

declare(strict_types=1);

namespace MergeMultisite\Audit;

use MergeMultisite\Config\MergeConfig;
use MergeMultisite\Db\Connection;
use MergeMultisite\Migration\Site;

/**
 * A single, self-contained, read-only integrity check run by
 * `bin/multisite-integrity-checker.php`.
 *
 * Implementations must not write to the source database or filesystem.
 *
 * @package MergeMultisite
 */
interface AuditCheckInterface {

	/**
	 * A short, stable identifier shown in reports, e.g. "orphaned-post-author".
	 */
	public function name(): string;

	/**
	 * @param Connection  $source Read-only connection to the source multisite database.
	 * @param MergeConfig $config The loaded run configuration.
	 * @param Site[]      $sites  The sites in scope for this run (included, non-deleted).
	 *
	 * @return AuditFinding[]
	 */
	public function run( Connection $source, MergeConfig $config, array $sites ): array;
}
