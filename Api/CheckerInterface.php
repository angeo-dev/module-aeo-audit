<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Api;

use Angeo\AeoAudit\Model\Report\CheckResult;

interface CheckerInterface
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /**
     * Human-readable name of the check.
     */
    public function getName(): string;

    /**
     * Run the check for a given store base URL.
     * Returns a CheckResult value object.
     */
    public function check(string $baseUrl): CheckResult;
}
