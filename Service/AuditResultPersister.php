<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;

/**
 * Single owner of the "persist an audit report" flow.
 *
 * Before 4.0.0 the populate → save → prune sequence was duplicated in the
 * CLI command, the cron job and the admin RunNow controller — three places
 * to update, three places to diverge. Every trigger path now funnels
 * through this service.
 *
 * @api
 * @since 4.0.0
 */
class AuditResultPersister
{
    public function __construct(
        private readonly AuditResultFactory  $auditResultFactory,
        private readonly AuditResultResource $auditResultResource,
    ) {
    }

    /**
     * Persist one report and prune per-store history.
     *
     * @param string $triggeredBy One of AuditResult::TRIGGERED_* constants
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function persist(AuditReport $report, string $triggeredBy): AuditResult
    {
        /** @var AuditResult $auditResult */
        $auditResult = $this->auditResultFactory->create();
        $auditResult->populateFromReport($report, $triggeredBy);

        $this->auditResultResource->save($auditResult);
        $this->auditResultResource->pruneOldResults($report->getStoreCode());

        return $auditResult;
    }

    /**
     * Persist a batch of reports.
     *
     * @param AuditReport[] $reports
     * @return AuditResult[]
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function persistAll(array $reports, string $triggeredBy): array
    {
        $results = [];
        foreach ($reports as $report) {
            $results[] = $this->persist($report, $triggeredBy);
        }
        return $results;
    }
}
