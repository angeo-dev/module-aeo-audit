<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Cron;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\ResourceModel\BotHit;
use Angeo\AeoAudit\Service\AuditResultPersister;
use Psr\Log\LoggerInterface;

/**
 * Scheduled AEO audit for all stores (schedule configurable since 4.0.0).
 * Saves results to DB so Admin Grid can display history, prunes audit
 * history to last 50 records per store, and enforces the GDPR retention
 * window on the bot-hit counter table.
 */
class AuditCron
{
    /**
     * @param AuditRunner $auditRunner
     * @param AuditResultPersister $persister
     * @param BotHit $botHitResource
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuditRunner          $auditRunner,
        private readonly AuditResultPersister $persister,
        private readonly BotHit               $botHitResource,
        private readonly Config               $config,
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
                $this->persister->persist($report, AuditResult::TRIGGERED_CRON);

                $this->logger->info(sprintf(
                    '[Angeo AEO] Cron audit complete — store: %s, score: %d%% (%s)',
                    $report->getStoreCode(),
                    $report->getScorePercent(),
                    $report->getScoreLabel()
                ));
            }
            // GDPR retention: bot-hit counters older than the window are dropped.
            $pruned = $this->botHitResource->pruneOlderThan($this->config->getBotHitRetentionDays());
            if ($pruned > 0) {
                $this->logger->info(sprintf('[Angeo AEO] Pruned %d expired bot-hit counter row(s).', $pruned));
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo AEO] Cron audit failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
