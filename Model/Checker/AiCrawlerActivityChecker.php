<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\BotHitSourceInterface;
use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\BotRegistry;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Live signal: is AI crawler traffic ACTUALLY reaching this store?
 *
 * Every other checker in this module audits configuration — "the door is
 * open". This one audits evidence — "someone walked through it". The two
 * together separate a store that is theoretically AI-ready from one that
 * answer engines demonstrably visit.
 *
 * Evidence arrives through pluggable BotHitSourceInterface adapters
 * (self-instrumentation table by default; webserver access log opt-in;
 * third parties can add CDN analytics adapters via di.xml). Results from
 * all available sources are merged, taking the max per bot — sources
 * overlap and we never want to double-count.
 *
 * Grading philosophy — deliberately gentle, because coverage is partial
 * (FPC/CDN hide traffic) and absence of evidence is weak evidence of
 * absence:
 *  - PASS: search-class crawlers observed in the window (the traffic that
 *    earns citations).
 *  - WARN: only training-class crawlers observed — the store feeds models
 *    without earning answer-engine presence.
 *  - WARN: silence — either still collecting, or crawlers are blocked
 *    upstream (cross-reference the WAF reality check).
 *  This checker never FAILs and never fails a CI build: severity is
 *  informational by design.
 *
 * @since 4.0.0
 */
class AiCrawlerActivityChecker extends AbstractChecker
{
    /**
     * @param BotHitSourceInterface[] $sources Injected via di.xml
     */
    public function __construct(
        HttpCache                    $httpCache,
        StoreUrlSampler              $urlSampler,
        private readonly BotRegistry $botRegistry,
        private readonly Config      $config,
        private readonly array       $sources = [],
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'AI crawler activity (observed)';
    }

    public function getCode(): string
    {
        return 'ai_crawler_activity';
    }

    public function getWeight(): float
    {
        return 0.5;
    }

    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_LIVE_SIGNAL;
    }

    public function getSeverity(): string
    {
        // Evidence coverage is inherently partial — this signal must never
        // fail a CI build regardless of weight-derived defaults.
        return CheckerInterface::SEVERITY_INFORMATIONAL;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $windowDays = $this->config->getLiveSignalWindowDays((int) $store->getId());

        $available = [];
        foreach ($this->sources as $source) {
            if ($source instanceof BotHitSourceInterface && $source->isAvailable($store)) {
                $available[] = $source;
            }
        }

        if ($available === []) {
            return $this->warn(
                'No evidence source is active — live crawler activity was not evaluated.',
                'Enable the built-in instrumentation (Stores → Configuration → Angeo AEO → Live Signal) '
                . 'or configure a webserver access log path.',
                ['sources' => [], 'window_days' => $windowDays]
            );
        }

        // Merge sources, max per bot — they observe overlapping traffic.
        $merged      = [];
        $sourceNotes = [];
        foreach ($available as $source) {
            $sourceNotes[$source->getCode()] = $source->getCoverageNote();
            foreach ($source->getHits($store, $windowDays) as $botCode => $data) {
                if (!isset($merged[$botCode]) || $data['hits'] > $merged[$botCode]['hits']) {
                    $merged[$botCode] = $data;
                }
            }
        }

        $byClass = [
            BotRegistry::CLASS_SEARCH   => [],
            BotRegistry::CLASS_TRAINING => [],
            BotRegistry::CLASS_FETCHER  => [],
        ];
        $totalHits = 0;
        foreach ($merged as $botCode => $data) {
            $bot = $this->botRegistry->get($botCode);
            if ($bot === null) {
                continue;
            }
            $byClass[$bot['class']][$bot['robots_token']] = $data['hits'];
            $totalHits += $data['hits'];
        }

        $details = [
            'window_days'    => $windowDays,
            'sources'        => $sourceNotes,
            'search_bots'    => $byClass[BotRegistry::CLASS_SEARCH],
            'training_bots'  => $byClass[BotRegistry::CLASS_TRAINING],
            'fetcher_bots'   => $byClass[BotRegistry::CLASS_FETCHER],
            'total_hits'     => $totalHits,
        ];

        $searchHits  = array_sum($byClass[BotRegistry::CLASS_SEARCH]);
        $fetcherHits = array_sum($byClass[BotRegistry::CLASS_FETCHER]);

        if ($searchHits > 0 || $fetcherHits > 0) {
            return $this->pass(
                sprintf(
                    'Answer-engine crawlers observed in the last %d days: %d search hit(s)%s, %d bot(s) total.',
                    $windowDays,
                    $searchHits,
                    $fetcherHits > 0 ? sprintf(', %d live-fetch hit(s)', $fetcherHits) : '',
                    count($merged)
                ),
                $details
            );
        }

        if (array_sum($byClass[BotRegistry::CLASS_TRAINING]) > 0) {
            return $this->warn(
                sprintf(
                    'Only TRAINING crawlers observed in the last %d days — models learn from this store, '
                    . 'but no answer-engine (search) crawler has visited.',
                    $windowDays
                ),
                'Verify search crawlers are permitted in robots.txt and not blocked at the CDN/WAF layer '
                . '(see the "Edge vs robots.txt consistency" signal).',
                $details
            );
        }

        return $this->warn(
            sprintf('No AI crawler activity observed in the last %d days.', $windowDays),
            'If the module was installed recently, data is still accumulating. Otherwise cross-check '
            . 'the "Edge vs robots.txt consistency" signal — silence at the PHP layer with an open '
            . 'robots.txt often means an upstream WAF/CDN block. Note: full-page-cache hits are '
            . 'invisible to the built-in source; an access-log or CDN source gives fuller coverage.',
            $details
        );
    }
}
