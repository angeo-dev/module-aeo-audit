<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Block\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Magento\Backend\Block\Template;

class View extends Template
{
    /** @var string */
    protected $_template = 'Angeo_AeoAudit::auditresult/view.phtml';

    /** @var AuditResult|null */
    private ?AuditResult $auditResult = null;

    /**
     * Set audit result model.
     *
     * @param AuditResult $auditResult
     * @return static
     */
    public function setAuditResult(AuditResult $auditResult): static
    {
        $this->auditResult = $auditResult;
        return $this;
    }

    /**
     * Get audit result model.
     *
     * @return AuditResult|null
     */
    public function getAuditResult(): ?AuditResult
    {
        return $this->auditResult;
    }

    /**
     * Get decoded checks array.
     *
     * @return array
     */
    public function getChecks(): array
    {
        return $this->auditResult?->getChecksDecoded() ?? [];
    }

    /**
     * Get score bar width as integer percentage.
     *
     * @return int
     */
    public function getScoreBarWidth(): int
    {
        return (int) ($this->auditResult?->getScore() ?? 0);
    }

    /**
     * Get hex color code for score level.
     *
     * @return string
     */
    public function getScoreColor(): string
    {
        $score = $this->auditResult?->getScore() ?? 0;
        return match (true) {
            $score >= 85 => '#28a745',
            $score >= 65 => '#ffc107',
            default      => '#dc3545',
        };
    }

    /**
     * Get emoji status icon for a check status string.
     *
     * @param string $status
     * @return string
     */
    public function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pass'  => '✅',
            'warn'  => '⚠️',
            default => '❌',
        };
    }

    /**
     * Get CSS class for a check status string.
     *
     * @param string $status
     * @return string
     */
    public function getStatusClass(string $status): string
    {
        return match ($status) {
            'pass'  => 'grid-severity-notice',
            'warn'  => 'grid-severity-minor',
            default => 'grid-severity-critical',
        };
    }

    /**
     * Get back URL for the audit results grid.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    /**
     * Get URL to run a new audit for this store.
     *
     * @return string
     */
    public function getRunNowUrl(): string
    {
        return $this->getUrl('*/*/runNow', [
            'store' => $this->auditResult?->getStoreCode(),
        ]);
    }
}
