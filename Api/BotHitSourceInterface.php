<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Api;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Evidence source for observed AI crawler activity.
 *
 * The live-signal checker does not care WHERE the evidence comes from — a
 * self-instrumentation table, an nginx/apache access log, a CDN analytics
 * API — only that a source can say "bot X was seen N times, last on date D".
 *
 * Design constraints every implementation MUST honour:
 *
 *  - PRIVACY: return aggregates only. Never expose or persist raw log lines,
 *    IP addresses, or any per-request data — access logs are personal data
 *    under GDPR; this module only ever handles (bot, class, date, count).
 *  - GRACEFUL ABSENCE: isAvailable() must never throw and must be cheap.
 *    An unreadable log file, a missing table, an unset config path — all of
 *    these mean "return false", not "return an error".
 *  - NO PRIVILEGE ESCALATION: never instruct the merchant to loosen file
 *    permissions globally. Documentation recommends targeted ACLs
 *    (setfacl) or logrotate copy hooks instead.
 *
 * Third-party sources (e.g. a Cloudflare analytics adapter) register via
 * di.xml on AiCrawlerActivityChecker's $sources argument.
 *
 * @api
 * @since 4.0.0
 */
interface BotHitSourceInterface
{
    /**
     * Machine-readable source identifier, e.g. "self_instrumentation".
     */
    public function getCode(): string;

    /**
     * Human-readable label for CLI / admin output.
     */
    public function getLabel(): string;

    /**
     * Can this source currently provide data for the store?
     * Must be cheap and must never throw.
     */
    public function isAvailable(StoreInterface $store): bool;

    /**
     * Aggregated hits per bot over the trailing window.
     *
     * @return array<string, array{hits: int, last_seen: string}> keyed by
     *         BotRegistry bot code; last_seen is Y-m-d.
     */
    public function getHits(StoreInterface $store, int $days): array;

    /**
     * Honest statement of what this source can and cannot see, rendered in
     * the check details (e.g. "PHP-layer only — full-page-cache hits are
     * not counted").
     */
    public function getCoverageNote(): string;
}
