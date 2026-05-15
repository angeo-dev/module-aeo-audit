<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Controller\Adminhtml\AuditResult;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Angeo_AeoAudit::audit_results';

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Angeo_AeoAudit::audit_results');
        $resultPage->getConfig()->getTitle()->prepend(__('AEO Audit Results'));
        return $resultPage;
    }
}
