<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;

class RunNow extends Action
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::audit_results';

    /**
     * @param Context $context
     * @param AuditRunner $auditRunner
     * @param AuditResultFactory $auditResultFactory
     * @param AuditResultResource $auditResultResource
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        Context $context,
        private readonly AuditRunner         $auditRunner,
        private readonly AuditResultFactory  $auditResultFactory,
        private readonly AuditResultResource $auditResultResource,
        private readonly RedirectFactory     $redirectFactory,
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
            $storeCode = $this->getRequest()->getParam('store') ?: null;
            $reports   = $this->auditRunner->runAll($storeCode);

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
            $this->messageManager->addErrorMessage(
                __('Audit failed: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
