<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Api;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

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
 *
 * @api
 * @since 3.0.0
 */
interface CheckerInterface
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    // ── Categories (v3) — used by AuditRunner filtering and admin grouping ──
    public const CATEGORY_TECHNICAL    = 'technical';    // Fast, deterministic HTTP / DB checks
    public const CATEGORY_LIVE_SIGNAL  = 'live_signal';  // External / log-based signals
    public const CATEGORY_EXTERNAL_API = 'external_api'; // Paid or rate-limited external APIs
    public const CATEGORY_FEED         = 'feed';         // Product feed / structured catalog

    // ── Severity (v3) — independent of weight; drives --fail-on-severity CLI flag ──
    public const SEVERITY_CRITICAL    = 'critical';
    public const SEVERITY_IMPORTANT   = 'important';
    public const SEVERITY_INFORMATIONAL = 'info';

    /**
     * Human-readable name shown in CLI table and Admin UI.
     */
    public function getName(): string;

    /**
     * Unique machine-readable identifier.
     *
     * Used in JSON output, Admin Grid columns, and cron result storage.
     * Example: "robots_txt", "product_schema"
     */
    public function getCode(): string;

    /**
     * Score weight (0.0–1.0). All weights are normalised during score calculation.
     *
     * Critical checks = 1.0, informational checks = 0.5.
     */
    public function getWeight(): float;

    /**
     * Checker category — used for grouping and selective execution.
     *
     * Default in AbstractChecker is CATEGORY_TECHNICAL. Override in checkers
     * that hit external APIs, parse logs, or generate cost.
     *
     * @since 3.0.0
     */
    public function getCategory(): string;

    /**
     * Severity level — independent of weight.
     *
     * Weight controls the score contribution; severity controls whether a
     * failure should fail a CI build via --fail-on-severity=critical.
     *
     * @since 3.0.0
     */
    public function getSeverity(): string;

    /**
     * Run the check against a store.
     *
     * The v3 contract passes the full Store object instead of just base URL —
     * checkers can now access store-scoped config, locale, currency, products.
     * The default base URL is available via $store->getBaseUrl().
     *
     * Must never throw — catch all exceptions internally and return CheckResult::fail().
     *
     * @param StoreInterface $store Store to audit
     * @since 3.0.0 — signature changed from check(string $baseUrl)
     */
    public function check(StoreInterface $store): CheckResult;

    /**
     * Composer command to fix this signal when it fails.
     *
     * Shown in CLI output after FAIL/WARN results.
     * Return empty string if no fix module exists.
     *
     * Example: "composer require angeo/module-llms-txt"
     */
    public function getFixCommand(): string;
}
