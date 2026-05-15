<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::audit_results';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param AuditResultFactory $auditResultFactory
     * @param AuditResultResource $auditResultResource
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory          $resultPageFactory,
        private readonly AuditResultFactory   $auditResultFactory,
        private readonly AuditResultResource  $auditResultResource,
        private readonly RedirectFactory      $redirectFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('id');

        $auditResult = $this->auditResultFactory->create();
        $this->auditResultResource->load($auditResult, $id);

        if (!$auditResult->getId()) {
            $this->messageManager->addErrorMessage(__('Audit result not found.'));
            return $this->redirectFactory->create()->setPath('*/*/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Angeo_AeoAudit::audit_results');
        $resultPage->getConfig()->getTitle()->prepend(
            __('AEO Audit — %1 — %2%%', $auditResult->getStoreCode(), $auditResult->getScore())
        );

        // Pass the loaded model to the block via registry alternative
        $resultPage->getLayout()->getBlock('angeo.aeo.audit.result.view')
            ?->setAuditResult($auditResult);

        return $resultPage;
    }
}
