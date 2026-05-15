<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class AuditRunner
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param CheckerInterface[] $checkers Injected via di.xml — third-party modules can extend this array
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly array $checkers = [],
    ) {
    }

    /**
     * Run all checkers against a specific store (by code) or all active stores.
     *
     * @param string|null $storeCode
     * @return AuditReport[]
     */
    public function runAll(?string $storeCode = null): array
    {
        $stores = $storeCode
            ? [$this->storeManager->getStore($storeCode)]
            : $this->storeManager->getStores();

        $reports = [];
        foreach ($stores as $store) {
            // Use secure URL if available, fall back to base URL
            $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true)
                ?: $store->getBaseUrl(UrlInterface::URL_TYPE_WEB);

            $report = new AuditReport(rtrim($baseUrl, '/'), $store->getCode());

            foreach ($this->checkers as $checker) {
                try {
                    $result = $checker->check($baseUrl);
                } catch (\Throwable $e) {
                    // Safety net — checkers should never throw, but just in case
                    $result = CheckResult::fail(
                        $checker->getName(),
                        'Check threw an unexpected exception: ' . $e->getMessage(),
                        'Report this as a bug at https://github.com/angeo-dev/module-aeo-audit/issues',
                        [],
                        $checker->getCode(),
                        $checker->getWeight(),
                    );
                }
                $report->addResult($result);
            }

            $reports[] = $report;
        }

        return $reports;
    }
}
