<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Block\Adminhtml;

use Angeo\AeoAudit\Model\ResourceModel\AuditResult\CollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class ScoreTrend extends Template
{
    protected $_template = 'Angeo_AeoAudit::score_trend.phtml';

    public function __construct(
        Context $context,
        private readonly CollectionFactory    $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getHistoryUrl(): string
    {
        return $this->getUrl('angeo_aeo_audit/auditresult/history');
    }

    public function getRunNowUrl(): string
    {
        return $this->getUrl('angeo_aeo_audit/auditresult/runNow');
    }

    public function getResultsIndexUrl(): string
    {
        return $this->getUrl('angeo_aeo_audit/auditresult/index');
    }

    /**
     * Returns available store codes that have at least one audit result.
     *
     * @return string[]
     */
    public function getStoresWithData(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect('store_code');
        $collection->getSelect()->group('store_code');

        $stores = [];
        foreach ($collection as $item) {
            $stores[] = $item->getData('store_code');
        }

        return $stores;
    }

    /**
     * Returns the latest score per store.
     *
     * @return array<string, int>
     */
    public function getLatestScores(): array
    {
        $scores = [];

        foreach ($this->getStoresWithData() as $storeCode) {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToSelect(['store_code', 'score', 'score_label', 'pass_count', 'warn_count', 'fail_count', 'created_at']);
            $collection->addFieldToFilter('store_code', $storeCode);
            $collection->setOrder('created_at', 'DESC');
            $collection->setPageSize(1);

            $latest = $collection->getFirstItem();
            if ($latest->getId()) {
                $scores[$storeCode] = [
                    'score'      => (int) $latest->getData('score'),
                    'label'      => (string) $latest->getData('score_label'),
                    'pass'       => (int) $latest->getData('pass_count'),
                    'warn'       => (int) $latest->getData('warn_count'),
                    'fail'       => (int) $latest->getData('fail_count'),
                    'created_at' => (string) $latest->getData('created_at'),
                ];
            }
        }

        return $scores;
    }

    public function hasAuditData(): bool
    {
        return !empty($this->getStoresWithData());
    }
}
