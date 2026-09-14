<?php

declare(strict_types=1);

namespace MergeMultisite\Db;

use RuntimeException;

/**
 * Thrown when a database connection cannot be established. The message
 * includes troubleshooting steps, so CLI entry points can print it
 * verbatim instead of a bare PDO stack trace.
 *
 * @package MergeMultisite
 */
class ConnectionException extends RuntimeException {

}
