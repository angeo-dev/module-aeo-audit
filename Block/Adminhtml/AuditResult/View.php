<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Block\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class View extends Template
{
    protected $_template = 'Angeo_AeoAudit::auditresult/view.phtml';

    private ?AuditResult $auditResult = null;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function setAuditResult(AuditResult $auditResult): static
    {
        $this->auditResult = $auditResult;
        return $this;
    }

    public function getAuditResult(): ?AuditResult
    {
        return $this->auditResult;
    }

    public function getChecks(): array
    {
        return $this->auditResult?->getChecksDecoded() ?? [];
    }

    public function getScoreBarWidth(): int
    {
        return (int) ($this->auditResult?->getScore() ?? 0);
    }

    public function getScoreColor(): string
    {
        $score = $this->auditResult?->getScore() ?? 0;
        return match (true) {
            $score >= 85 => '#28a745',
            $score >= 65 => '#ffc107',
            default      => '#dc3545',
        };
    }

    public function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pass'  => '✅',
            'warn'  => '⚠️',
            default => '❌',
        };
    }

    public function getStatusClass(string $status): string
    {
        return match ($status) {
            'pass'  => 'grid-severity-notice',
            'warn'  => 'grid-severity-minor',
            default => 'grid-severity-critical',
        };
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getRunNowUrl(): string
    {
        return $this->getUrl('*/*/runNow', [
            'store' => $this->auditResult?->getStoreCode(),
        ]);
    }
}
