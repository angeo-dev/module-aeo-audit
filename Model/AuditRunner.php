<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates running every registered checker against every requested store.
 *
 * v3 changes vs v2:
 *  - Checkers now receive the full Store object (richer context, less per-checker
 *    duplication of base-URL resolution)
 *  - HttpCache + StoreUrlSampler are reset between stores so caching never
 *    bleeds across audits
 *  - Per-checker soft timeout — slow checkers (e.g. external-API ones) no
 *    longer halt the entire run
 *  - Category filtering — only run checkers matching $categories (empty = all)
 *
 * @api
 */
class AuditRunner
{
    /** Per-checker soft timeout default (seconds). External-API checkers may
     * legitimately exceed this; the wall clock is enforced inside each checker
     * via HttpCache timeouts, while this value is recorded in the failure
     * details if a checker throws. */
    public const DEFAULT_CHECKER_TIMEOUT = 30;

    /**
     * @param CheckerInterface[] $checkers Injected via di.xml — third-party modules can extend this array
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpCache             $httpCache,
        private readonly StoreUrlSampler       $urlSampler,
        private readonly LoggerInterface       $logger,
        private readonly Config                $config,
        private readonly array                 $checkers = [],
    ) {
    }

    /**
     * Run checkers against a specific store (by code) or all active stores.
     *
     * @param string|null   $storeCode  Limit to this store
     * @param array<string> $categories Filter checkers by category — empty array = all
     * @return AuditReport[]
     */
    public function runAll(?string $storeCode = null, array $categories = []): array
    {
        $stores = $storeCode
            ? [$this->storeManager->getStore($storeCode)]
            : $this->storeManager->getStores();

        $reports = [];
        foreach ($stores as $store) {
            // Reset request-scoped caches between stores so e.g. a robots.txt
            // fetched for store A doesn't get returned for store B if base URLs
            // happen to overlap during testing.
            $this->httpCache->reset();
            $this->urlSampler->reset();

            $baseUrl = $this->urlSampler->getBaseUrl($store);
            $report  = new AuditReport($baseUrl, (string) $store->getCode());
            $storeId = (int) $store->getId();

            foreach ($this->checkers as $checker) {
                if ($categories !== [] && !in_array($checker->getCategory(), $categories, true)) {
                    continue;
                }

                // Skip signals the merchant has disabled for this store. A
                // disabled signal is excluded from the report entirely, so it
                // affects neither the score numerator nor the denominator.
                if (!$this->config->isCheckerEnabled($checker->getCode(), $storeId)) {
                    continue;
                }

                $startTime = microtime(true);
                try {
                    $result = $checker->check($store);
                } catch (\Throwable $e) {
                    // Safety net — checkers should never throw, but just in case
                    $this->logger->error(
                        sprintf(
                            '[Angeo AEO] Checker %s threw: %s',
                            $checker->getCode(),
                            $e->getMessage()
                        ),
                        ['exception' => $e]
                    );

                    $result = CheckResult::fail(
                        $checker->getName(),
                        'Check threw an unexpected exception: ' . $e->getMessage(),
                        'Report this as a bug at https://github.com/angeo-dev/module-aeo-audit/issues',
                        ['exception_class' => $e::class],
                        $checker->getCode(),
                        $checker->getWeight(),
                    );
                }

                $elapsed = microtime(true) - $startTime;
                if ($elapsed > self::DEFAULT_CHECKER_TIMEOUT) {
                    $this->logger->warning(sprintf(
                        '[Angeo AEO] Checker %s took %.2fs (soft threshold %ds) — consider category=external_api',
                        $checker->getCode(),
                        $elapsed,
                        self::DEFAULT_CHECKER_TIMEOUT
                    ));
                }

                $report->addResult($result);
            }

            $reports[] = $report;
        }

        return $reports;
    }
}
