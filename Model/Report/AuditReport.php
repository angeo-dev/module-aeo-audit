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
        private readonly string $storeCode,
    ) {
    }

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

    /**
     * Weighted score 0–100.
     *
     * PASS = full weight, WARN = 0.5 × weight, FAIL = 0
     * All weights normalised so they don't need to sum to 1.
     */
    public function getScorePercent(): int
    {
        $totalWeight  = array_sum(array_map(fn(CheckResult $r) => $r->getWeight(), $this->results));
        $earnedWeight = array_sum(array_map(fn(CheckResult $r) => $r->getWeightedScore(), $this->results));

        if ($totalWeight <= 0.0) {
            return 0;
        }

        return (int) round(($earnedWeight / $totalWeight) * 100);
    }

    public function getScoreLabel(): string
    {
        return match (true) {
            $this->getScorePercent() >= 85 => 'Excellent',
            $this->getScorePercent() >= 65 => 'Good',
            $this->getScorePercent() >= 40 => 'Needs Improvement',
            default                        => 'Critical',
        };
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

    public function countByStatus(string $status): int
    {
        return count(array_filter($this->results, fn(CheckResult $r) => $r->getStatus() === $status));
    }

    public function toArray(): array
    {
        return [
            'store_code'  => $this->storeCode,
            'store_url'   => $this->storeUrl,
            'score'       => $this->getScorePercent(),
            'label'       => $this->getScoreLabel(),
            'pass'        => $this->getPassCount(),
            'warn'        => $this->getWarnCount(),
            'fail'        => $this->getFailCount(),
            'checks'      => array_map(fn(CheckResult $r) => [
                'code'           => $r->getCheckCode(),
                'name'           => $r->getCheckName(),
                'status'         => $r->getStatus(),
                'message'        => $r->getMessage(),
                'recommendation' => $r->getRecommendation(),
                'details'        => $r->getDetails(),
                'weight'         => $r->getWeight(),
            ], $this->results),
        ];
    }
}
