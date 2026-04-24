<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Api;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * AEO Check contract.
 *
 * Implement this interface and register via di.xml to add custom checks.
 * Third-party modules inject additional checkers into AuditRunner's $checkers array.
 *
 * @example di.xml:
 *   <type name="Angeo\AeoAudit\Model\AuditRunner">
 *     <arguments><argument name="checkers" xsi:type="array">
 *       <item name="my_check" xsi:type="object">Vendor\Module\Model\Checker\MyChecker</item>
 *     </argument></arguments>
 *   </type>
 */
interface CheckerInterface
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /**
     * Human-readable name shown in CLI table and Admin UI.
     */
    public function getName(): string;

    /**
     * Unique machine-readable identifier.
     * Used in JSON output, Admin Grid columns, and cron result storage.
     * Example: "robots_txt", "product_schema"
     */
    public function getCode(): string;

    /**
     * Score weight (0.0–1.0). All weights are normalised during score calculation.
     * Critical checks = 1.0, informational checks = 0.5.
     */
    public function getWeight(): float;

    /**
     * Run the check against a store base URL.
     * Must never throw — catch all exceptions internally and return CheckResult::fail().
     */
    public function check(string $baseUrl): CheckResult;

    /**
     * Composer command to fix this signal when it fails.
     * Shown in CLI output after FAIL/WARN results.
     * Return empty string if no fix module exists.
     *
     * Example: "composer require angeo/module-llms-txt"
     */
    public function getFixCommand(): string;
}
