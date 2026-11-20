<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Block\Adminhtml\AuditResult;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\Report\Explanations;
use Angeo\AeoAudit\Model\Report\ResultPresenter;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult\CollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;

class View extends Template
{
    /** @var string */
    protected $_template = 'Angeo_AeoAudit::auditresult/view.phtml';

    /** @var AuditResult|null */
    private ?AuditResult $auditResult = null;

    /** @var array<string,mixed> */
    private array $memo = [];

    private readonly ResultPresenter $presenter;
    private readonly Explanations $explanations;
    private readonly CollectionFactory $collectionFactory;

    /**
     * The new dependencies are optional and come last, so a class that
     * extends this block and calls parent::__construct($context, $data)
     * keeps working (Magento's backward-compatible constructor pattern).
     *
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        array $data = [],
        ?ResultPresenter $presenter = null,
        ?Explanations $explanations = null,
        ?CollectionFactory $collectionFactory = null
    ) {
        parent::__construct($context, $data);
        $om = ObjectManager::getInstance();
        $this->presenter         = $presenter ?? $om->get(ResultPresenter::class);
        $this->explanations      = $explanations ?? $om->get(Explanations::class);
        $this->collectionFactory = $collectionFactory ?? $om->get(CollectionFactory::class);
    }

    public function getPresenter(): ResultPresenter
    {
        return $this->presenter;
    }

    /**
     * Checks of this audit, normalized, with points-if-fixed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getPresentedChecks(): array
    {
        return $this->memo['checks'] ??= $this->presenter->normalize($this->getChecks());
    }

    /** @return array<int,array<string,mixed>> */
    public function getOrderedChecks(): array
    {
        return $this->presenter->ordered($this->getPresentedChecks());
    }

    /** @return array<int,array<string,mixed>> */
    public function getScoredChecks(): array
    {
        return $this->presenter->scored($this->getPresentedChecks());
    }

    /** @return array<int,array<string,mixed>> */
    public function getFixFirst(): array
    {
        return $this->presenter->fixFirst($this->getPresentedChecks());
    }

    /** @return array<int,array<string,mixed>> */
    public function getVisibilityChecks(): array
    {
        return $this->presenter->visibility($this->getPresentedChecks());
    }

    public function getNotApplicableCount(): int
    {
        return count($this->presenter->notApplicable($this->getPresentedChecks()));
    }

    /** @return array{circumference:float,dash:float,band:string} */
    public function getRing(): array
    {
        return $this->presenter->ring((int) ($this->auditResult?->getScore() ?? 0));
    }

    /**
     * The audit of the same store just before this one, if any.
     */
    public function getPreviousResult(): ?AuditResult
    {
        if (array_key_exists('previous', $this->memo)) {
            return $this->memo['previous'];
        }
        $previous = null;
        if ($this->auditResult !== null && $this->auditResult->getId()) {
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('store_code', (string) $this->auditResult->getStoreCode());
            $collection->addFieldToFilter('id', ['lt' => (int) $this->auditResult->getId()]);
            $collection->setOrder('id', 'DESC');
            $collection->setPageSize(1);
            $item = $collection->getFirstItem();
            $previous = $item instanceof AuditResult && $item->getId() ? $item : null;
        }

        return $this->memo['previous'] = $previous;
    }

    public function getScoreDelta(): ?int
    {
        $previous = $this->getPreviousResult();

        return $previous === null || $this->auditResult === null
            ? null
            : (int) $this->auditResult->getScore() - (int) $previous->getScore();
    }

    /**
     * @return array{better:array<int,array<string,string>>,worse:array<int,array<string,string>>,
     *     added:array<int,string>,removed:array<int,string>}|null
     */
    public function getChanges(): ?array
    {
        $previous = $this->getPreviousResult();
        if ($previous === null) {
            return null;
        }

        return $this->presenter->changes(
            $this->getPresentedChecks(),
            $this->presenter->normalize($previous->getChecksDecoded())
        );
    }

    /** @return array{what:string,why:string,how:string}|null */
    public function getExplanation(string $code): ?array
    {
        return $this->explanations->get($code);
    }

    /** @return array<string,string> */
    public function getGlossary(): array
    {
        return $this->explanations->glossary();
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,string>
     */
    public function getEvidence(array $details): array
    {
        return $this->presenter->evidence($details);
    }

    public function getPreviousUrl(): string
    {
        $previous = $this->getPreviousResult();

        return $previous === null ? '' : $this->getUrl('*/*/view', ['id' => (int) $previous->getId()]);
    }

    public function getTrendUrl(): string
    {
        return $this->getUrl('*/*/trend');
    }

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
     * Get URL to run a new audit. The store code is submitted as a POST
     * field (see template) rather than embedded in the URL.
     *
     * @return string
     */
    public function getRunNowUrl(): string
    {
        return $this->getUrl('*/*/runNow');
    }
}
