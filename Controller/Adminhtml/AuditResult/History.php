<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\ResourceModel\AuditResult\Collection;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * AJAX endpoint: score history per store for the trend chart.
 * GET angeo_aeo_audit/auditresult/history?store=default&days=30
 */
class History extends Action
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::audit_results';

    public function __construct(
        Context $context,
        private readonly JsonFactory       $jsonFactory,
        private readonly CollectionFactory $collectionFactory,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result    = $this->jsonFactory->create();
        $store     = (string) $this->getRequest()->getParam('store', '');
        $days      = max(7, min(365, (int) $this->getRequest()->getParam('days', 30)));

        try {
            /** @var Collection $collection */
            $collection = $this->collectionFactory->create();
            $collection->addFieldToSelect(['store_code', 'score', 'pass_count', 'warn_count', 'fail_count', 'triggered_by', 'created_at']);
            $collection->addFieldToFilter('created_at', [
                'gteq' => date('Y-m-d H:i:s', strtotime("-{$days} days")),
            ]);

            if ($store !== '') {
                $collection->addFieldToFilter('store_code', $store);
            }

            $collection->setOrder('created_at', 'ASC');

            $points     = [];
            $byStore    = [];

            foreach ($collection as $item) {
                $storeCode = $item->getData('store_code');
                $byStore[$storeCode][] = [
                    'date'         => $item->getData('created_at'),
                    'score'        => (int) $item->getData('score'),
                    'pass'         => (int) $item->getData('pass_count'),
                    'warn'         => (int) $item->getData('warn_count'),
                    'fail'         => (int) $item->getData('fail_count'),
                    'triggered_by' => $item->getData('triggered_by'),
                ];
            }

            // Build available stores list
            $stores = array_keys($byStore);

            return $result->setData([
                'success' => true,
                'stores'  => $stores,
                'data'    => $byStore,
                'days'    => $days,
            ]);

        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
