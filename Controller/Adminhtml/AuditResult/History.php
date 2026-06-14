<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\ResourceModel\AuditResult\Collection;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * AJAX endpoint: score history per store for the trend chart.
 * GET angeo_aeo_audit/auditresult/history?store=default&days=30
 */
class History extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::audit_results';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param CollectionFactory $collectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory       $jsonFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly LoggerInterface   $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        $store  = trim((string) $this->getRequest()->getParam('store', ''));
        $days   = max(7, min(365, (int) $this->getRequest()->getParam('days', 30)));

        // Whitelist store-code characters; anything else means "all stores".
        if ($store !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $store)) {
            $store = '';
        }

        try {
            /** @var Collection $collection */
            $collection = $this->collectionFactory->create();
            $collection->addFieldToSelect(
                ['store_code', 'score', 'pass_count', 'warn_count', 'fail_count', 'triggered_by', 'created_at']
            );
            // created_at is stored in UTC (CURRENT_TIMESTAMP on a UTC-configured
            // DB per Magento convention) — compare against UTC, not server TZ.
            $collection->addFieldToFilter('created_at', [
                'gteq' => gmdate('Y-m-d H:i:s', strtotime("-{$days} days")),
            ]);

            if ($store !== '') {
                $collection->addFieldToFilter('store_code', $store);
            }

            $collection->setOrder('created_at', 'ASC');

            $byStore = [];

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

            return $result->setData([
                'success' => true,
                'stores'  => array_keys($byStore),
                'data'    => $byStore,
                'days'    => $days,
            ]);
        } catch (\Throwable $e) {
            // Log internally; never expose exception details to the client.
            $this->logger->error('[Angeo AEO] History endpoint failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $result->setData([
                'success' => false,
                'error'   => (string) __('Unable to load audit history.'),
            ]);
        }
    }
}
