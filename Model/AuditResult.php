<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * ORM model for angeo_aeo_audit_result table.
 *
 * @method int    getId()
 * @method string getStoreCode()
 * @method string getStoreUrl()
 * @method int    getScore()
 * @method string getScoreLabel()
 * @method int    getPassCount()
 * @method int    getWarnCount()
 * @method int    getFailCount()
 * @method string getChecksJson()
 * @method string getTriggeredBy()
 * @method string getCreatedAt()
 */
class AuditResult extends AbstractModel
{
    public const TRIGGERED_MANUAL = 'manual';
    public const TRIGGERED_CRON   = 'cron';
    public const TRIGGERED_API    = 'api';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\AuditResult::class);
    }

    public function getChecksDecoded(): array
    {
        return json_decode((string) $this->getData('checks_json'), true) ?? [];
    }

    public function populateFromReport(Report\AuditReport $report, string $triggeredBy = self::TRIGGERED_MANUAL): static
    {
        $data = $report->toArray();

        $this->setData('store_code', $data['store_code']);
        $this->setData('store_url', $data['store_url']);
        $this->setData('score', $data['score']);
        $this->setData('score_label', $data['label']);
        $this->setData('pass_count', $data['pass']);
        $this->setData('warn_count', $data['warn']);
        $this->setData('fail_count', $data['fail']);
        $this->setData('checks_json', json_encode($data['checks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->setData('triggered_by', $triggeredBy);

        return $this;
    }
}
