<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Observer;

use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\Report\Explanations;
use Angeo\AeoAudit\Model\Report\ResultPresenter;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult\CollectionFactory;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Gives the optional AI assistant (angeo/module-ai-assist) the facts of an
 * audit page. This module does not depend on the assistant: without it the
 * event is never dispatched. Contract: module-ai-assist CONTEXT.md.
 *
 * Sources answered:
 *  - aeo_audit        ref = audit result id
 *  - aeo_audit_trend  ref ignored: latest audits of every store
 */
class AiAssistContext implements ObserverInterface
{
    private const ACL = 'Angeo_AeoAudit::audit_results';

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly AuditResultFactory $resultFactory,
        private readonly AuditResultResource $resultResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResultPresenter $presenter,
        private readonly Explanations $explanations
    ) {
    }

    public function execute(Observer $observer): void
    {
        $source  = (string) $observer->getData('source');
        $context = $observer->getData('context');
        if (!in_array($source, ['aeo_audit', 'aeo_audit_trend'], true) || !$context instanceof DataObject) {
            return;
        }
        if (!$this->authorization->isAllowed(self::ACL)) {
            $context->setData('denied', true);
            return;
        }

        if ($source === 'aeo_audit') {
            $this->audit((string) $observer->getData('ref'), $context);
        } else {
            $this->trend($context);
        }
    }

    private function audit(string $ref, DataObject $context): void
    {
        if (!ctype_digit($ref)) {
            return;
        }
        $result = $this->resultFactory->create();
        $this->resultResource->load($result, (int) $ref);
        if (!$result->getId()) {
            return;
        }

        $checks   = $this->presenter->normalize($result->getChecksDecoded());
        $previous = $this->previous($result);
        $prevChecks = $previous !== null ? $this->presenter->normalize($previous->getChecksDecoded()) : null;

        $signals = [];
        $help    = [];
        foreach ($this->presenter->ordered($checks) as $c) {
            if ($c['visibility']) {
                continue;
            }
            $signals[] = [
                'code'            => $c['code'],
                'name'            => $c['name'],
                'status'          => $c['applicable'] ? $c['status'] : 'not_applicable',
                'weight'          => $c['weight'],
                'points_if_fixed' => $c['gain'],
                'message'         => $c['message'],
                'recommendation'  => $c['status'] !== 'pass' ? $c['recommendation'] : '',
                'fix_command'     => $c['status'] !== 'pass' ? $c['fix_command'] : '',
                'evidence'        => $c['status'] !== 'pass' ? $this->presenter->evidence($c['details'], 8, 200) : [],
            ];
            if ($c['applicable'] && $c['status'] !== 'pass' && ($e = $this->explanations->get($c['code'])) !== null) {
                $help[$c['code']] = $e;
            }
        }

        $visibility = [];
        foreach ($this->presenter->visibility($checks) as $v) {
            $visibility[] = [
                'name'    => $v['name'],
                'status'  => $v['status'],
                'score'   => $v['details']['score'] ?? null,
                'margin'  => $v['details']['score_margin'] ?? null,
                'message' => $v['message'],
            ];
        }

        $context->setData([
            'title'    => sprintf('AEO audit #%d, store %s, %s', (int) $result->getId(), (string) $result->getStoreCode(), (string) $result->getCreatedAt()),
            'summary'  => 'An AEO readiness audit of a Magento store: how well AI systems can find, read and trust it. '
                . 'Readiness score 0–100 = weighted share of passed signals (warning = half). '
                . 'Measured visibility is reported apart from readiness.',
            'facts'    => [
                'store_url'                => (string) $result->getStoreUrl(),
                'audited_at'               => (string) $result->getCreatedAt(),
                'readiness_score'          => (int) $result->getScore(),
                'rating'                   => (string) $result->getScoreLabel(),
                'previous_readiness_score' => $previous !== null ? (int) $previous->getScore() : null,
                'counts'                   => [
                    'pass' => (int) $result->getPassCount(),
                    'warn' => (int) $result->getWarnCount(),
                    'fail' => (int) $result->getFailCount(),
                    'not_applicable' => count($this->presenter->notApplicable($checks)),
                ],
                'signals'                  => $signals,
                'measured_visibility'      => $visibility,
                'changes_since_previous'   => $prevChecks !== null ? $this->presenter->changes($checks, $prevChecks) : null,
                'what_the_failing_signals_mean' => $help,
            ],
            'glossary' => $this->explanations->glossary(),
        ]);
    }

    private function trend(DataObject $context): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect(['id', 'store_code', 'score', 'created_at', 'checks_json']);
        $collection->setOrder('id', 'DESC');
        $collection->setPageSize(60);

        $byStore = [];
        foreach ($collection as $item) {
            $store = (string) $item->getData('store_code');
            if (count($byStore[$store] ?? []) >= 12) {
                continue;
            }
            $checks = json_decode((string) $item->getData('checks_json'), true);
            $byStore[$store][] = [
                'id'         => (int) $item->getId(),
                'created_at' => (string) $item->getData('created_at'),
                'score'      => (int) $item->getData('score'),
                'checks'     => is_array($checks) ? $checks : [],
            ];
        }
        if ($byStore === []) {
            return;
        }

        $stores = [];
        foreach ($byStore as $store => $audits) {
            $audits  = array_reverse($audits);
            $map     = $this->presenter->heatmap($audits);
            $changed = [];
            foreach ($map['rows'] as $row) {
                $seen = array_values(array_unique(array_filter($row['cells'], static fn (string $s): bool => $s !== 'none')));
                if (count($seen) > 1) {
                    $changed[] = ['signal' => $row['name'], 'results_oldest_first' => array_values($row['cells'])];
                }
            }
            $stores[$store] = [
                'scores_oldest_first'      => array_map(static fn (array $a): array => ['at' => $a['created_at'], 'score' => $a['score']], $audits),
                'signals_that_changed'     => $changed,
            ];
        }

        $context->setData([
            'title'    => 'AEO score trend, last audits per store',
            'summary'  => 'Readiness scores over time and the signals whose result changed between audits.',
            'facts'    => ['stores' => $stores],
            'glossary' => $this->explanations->glossary(),
        ]);
    }

    private function previous(AuditResult $result): ?AuditResult
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('store_code', (string) $result->getStoreCode());
        $collection->addFieldToFilter('id', ['lt' => (int) $result->getId()]);
        $collection->setOrder('id', 'DESC');
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        return $item instanceof AuditResult && $item->getId() ? $item : null;
    }
}
