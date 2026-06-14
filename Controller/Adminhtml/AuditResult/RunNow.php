<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

/**
 * Triggers an on-demand audit run.
 *
 * POST-only (HttpPostActionInterface) since 3.1.0 — the action mutates state
 * (runs HTTP checks, writes results, prunes history) and therefore must not
 * be reachable via GET links. Magento validates the admin form key for POST.
 */
class RunNow extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::run_audit';

    /**
     * @param Context $context
     * @param AuditRunner $auditRunner
     * @param AuditResultFactory $auditResultFactory
     * @param AuditResultResource $auditResultResource
     * @param RedirectFactory $redirectFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly AuditRunner         $auditRunner,
        private readonly AuditResultFactory  $auditResultFactory,
        private readonly AuditResultResource $auditResultResource,
        private readonly RedirectFactory     $redirectFactory,
        private readonly LoggerInterface     $logger,
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $redirect = $this->redirectFactory->create();

        try {
            $storeCode = $this->sanitizeStoreCode(
                (string) $this->getRequest()->getParam('store', '')
            );
            $reports = $this->auditRunner->runAll($storeCode);

            foreach ($reports as $report) {
                /** @var AuditResult $result */
                $result = $this->auditResultFactory->create();
                $result->populateFromReport($report, AuditResult::TRIGGERED_MANUAL);
                $this->auditResultResource->save($result);
                $this->auditResultResource->pruneOldResults($report->getStoreCode());
            }

            $this->messageManager->addSuccessMessage(
                __('AEO audit completed for %1 store(s).', count($reports))
            );
        } catch (\Throwable $e) {
            // Log full details; show a generic message to avoid leaking internals.
            $this->logger->error('[Angeo AEO] Manual audit run failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->messageManager->addErrorMessage(
                __('The audit could not be completed. Please check the system log for details.')
            );
        }

        return $redirect->setPath('*/*/index');
    }

    /**
     * Store codes may contain only word characters and dashes; anything else
     * is discarded (audit then runs for all stores).
     */
    private function sanitizeStoreCode(string $storeCode): ?string
    {
        $storeCode = trim($storeCode);
        if ($storeCode === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $storeCode)) {
            return null;
        }
        return $storeCode;
    }
}
