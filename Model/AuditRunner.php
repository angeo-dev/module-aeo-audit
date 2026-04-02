<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Magento\Store\Model\StoreManagerInterface;

class AuditRunner
{
    /**
     * @param CheckerInterface[] $checkers
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly array $checkers = []
    ) {}

    /**
     * Run all checkers against a specific store (by code) or all stores.
     *
     * @return AuditReport[]
     */
    public function runAll(?string $storeCode = null): array
    {
        $stores = $storeCode
            ? [$this->storeManager->getStore($storeCode)]
            : $this->storeManager->getStores();

        $reports = [];
        foreach ($stores as $store) {
            $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            $report  = new AuditReport($baseUrl, $store->getCode());

            foreach ($this->checkers as $checker) {
                $result = $checker->check($baseUrl);
                $report->addResult($result);
            }

            $reports[] = $report;
        }

        return $reports;
    }
}
