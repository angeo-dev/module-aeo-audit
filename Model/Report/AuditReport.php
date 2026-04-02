<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;

class AuditReport
{
    /** @var CheckResult[] */
    private array $results = [];

    public function __construct(
        private readonly string $storeUrl,
        private readonly string $storeCode
    ) {}

    public function addResult(CheckResult $result): void
    {
        $this->results[] = $result;
    }

    /** @return CheckResult[] */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getStoreUrl(): string
    {
        return $this->storeUrl;
    }

    public function getStoreCode(): string
    {
        return $this->storeCode;
    }

    public function getScore(): int
    {
        return array_sum(array_map(fn(CheckResult $r) => $r->getScore(), $this->results));
    }

    public function getMaxScore(): int
    {
        return count($this->results) * 2;
    }

    public function getScorePercent(): int
    {
        if ($this->getMaxScore() === 0) {
            return 0;
        }
        return (int) round(($this->getScore() / $this->getMaxScore()) * 100);
    }

    public function getScoreLabel(): string
    {
        $pct = $this->getScorePercent();
        return match (true) {
            $pct >= 85 => 'Excellent',
            $pct >= 65 => 'Good',
            $pct >= 40 => 'Needs Improvement',
            default    => 'Critical',
        };
    }

    public function countByStatus(string $status): int
    {
        return count(array_filter($this->results, fn(CheckResult $r) => $r->getStatus() === $status));
    }

    public function getPassCount(): int
    {
        return $this->countByStatus(CheckerInterface::STATUS_PASS);
    }

    public function getWarnCount(): int
    {
        return $this->countByStatus(CheckerInterface::STATUS_WARN);
    }

    public function getFailCount(): int
    {
        return $this->countByStatus(CheckerInterface::STATUS_FAIL);
    }
}
