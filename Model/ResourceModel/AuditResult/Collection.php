<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\ResourceModel\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * Initialise model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AuditResult::class, AuditResultResource::class);
    }
}
