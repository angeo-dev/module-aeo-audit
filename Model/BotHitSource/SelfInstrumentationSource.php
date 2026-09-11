<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\BotHitSource;

use Angeo\AeoAudit\Api\BotHitSourceInterface;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\ResourceModel\BotHit;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Evidence source backed by the module's own request instrumentation.
 *
 * Zero configuration, zero filesystem access, works on every hosting model
 * (VPS, Docker, PaaS, behind CDN) from the moment setup:upgrade creates the
 * counter table. This is the "installs and just works" source.
 *
 * Honest coverage limitation: the recorder plugin runs at the PHP layer, so
 * requests served entirely from full-page cache (Varnish / built-in FPC hit)
 * never reach it. Counted hits therefore represent cache-miss traffic — a
 * valid "bots are fetching fresh content" signal, but a lower bound, not a
 * total. The coverage note makes this explicit in every report.
 *
 * @since 4.0.0
 */
class SelfInstrumentationSource implements BotHitSourceInterface
{
    public function __construct(
        private readonly BotHit $botHitResource,
        private readonly Config $config,
    ) {
    }

    public function getCode(): string
    {
        return 'self_instrumentation';
    }

    public function getLabel(): string
    {
        return 'Built-in request instrumentation';
    }

    public function isAvailable(StoreInterface $store): bool
    {
        // Available whenever the recorder is enabled — even with zero rows,
        // "we are listening and heard nothing" is itself evidence.
        return $this->config->isInstrumentationEnabled((int) $store->getId());
    }

    /**
     * @inheritDoc
     */
    public function getHits(StoreInterface $store, int $days): array
    {
        return $this->botHitResource->getAggregates((int) $store->getId(), $days);
    }

    public function getCoverageNote(): string
    {
        return 'PHP-layer instrumentation: counts cache-miss requests only. '
            . 'Hits served entirely from full-page cache are not visible to this source.';
    }

    /**
     * Whether any data has ever been recorded for the store — used by the
     * checker to distinguish "silence" from "just installed".
     */
    public function hasAnyData(StoreInterface $store): bool
    {
        return $this->botHitResource->hasAnyData((int) $store->getId());
    }
}
