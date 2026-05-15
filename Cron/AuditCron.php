<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Cron;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Psr\Log\LoggerInterface;

/**
 * Weekly scheduled AEO audit for all stores.
 * Saves results to DB so Admin Grid can display history.
 * Prunes history to last 50 records per store.
 */
class AuditCron
{
    /**
     * @param AuditRunner $auditRunner
     * @param AuditResultFactory $auditResultFactory
     * @param AuditResultResource $auditResultResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuditRunner          $auditRunner,
        private readonly AuditResultFactory   $auditResultFactory,
        private readonly AuditResultResource  $auditResultResource,
        private readonly LoggerInterface      $logger,
    ) {
    }

    /**
     * Run scheduled AEO audit for all stores.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $reports = $this->auditRunner->runAll();

            foreach ($reports as $report) {
                /** @var AuditResult $auditResult */
                $auditResult = $this->auditResultFactory->create();
                $auditResult->populateFromReport($report, AuditResult::TRIGGERED_CRON);
                $this->auditResultResource->save($auditResult);
                $this->auditResultResource->pruneOldResults($report->getStoreCode());

                $this->logger->info(sprintf(
                    '[Angeo AEO] Cron audit complete — store: %s, score: %d%% (%s)',
                    $report->getStoreCode(),
                    $report->getScorePercent(),
                    $report->getScoreLabel()
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo AEO] Cron audit failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
