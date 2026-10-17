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
     * Weighted readiness score 0–100.
     *
     * PASS = full weight, WARN = 0.5 × weight, FAIL = 0
     * All weights normalised so they don't need to sum to 1.
     *
     * Two kinds of result are left out of the readiness score (since 4.3.0):
     *  - details['applicable'] === false: the signal does not apply to this
     *    store (e.g. an A2A card when UCP declares no a2a transport, or an
     *    optional add-on that is switched off). Counting it as a pass inflated
     *    the score; counting it as a warn punished stores for not opting in.
     *  - details['score_group'] === 'visibility': measured AI visibility
     *    (angeo/module-aeo-brand-visibility). That is an observation of what
     *    assistants say, not a technical readiness signal, so it is reported
     *    on its own via getVisibilityResults() instead of being blended in.
     */
    public function getScorePercent(): int
    {
        $scored = $this->getScoredResults();
        $totalWeight  = array_sum(array_map(fn(CheckResult $r) => $r->getWeight(), $scored));
        $earnedWeight = array_sum(array_map(fn(CheckResult $r) => $r->getWeightedScore(), $scored));

        if ($totalWeight <= 0.0) {
            return 0;
        }

        return (int) round(($earnedWeight / $totalWeight) * 100);
    }

    /**
     * Results that count towards the readiness score.
     *
     * @return CheckResult[]
     */
    public function getScoredResults(): array
    {
        return array_values(array_filter(
            $this->results,
            fn(CheckResult $r) => !self::isNotApplicable($r) && !self::isVisibilityResult($r)
        ));
    }

    /**
     * Measured-visibility results, reported apart from the readiness score.
     *
     * @return CheckResult[]
     */
    public function getVisibilityResults(): array
    {
        return array_values(array_filter(
            $this->results,
            fn(CheckResult $r) => self::isVisibilityResult($r) && !self::isNotApplicable($r)
        ));
    }

    public function getNotApplicableCount(): int
    {
        return count(array_filter($this->results, fn(CheckResult $r) => self::isNotApplicable($r)));
    }

    public static function isNotApplicable(CheckResult $result): bool
    {
        return ($result->getDetails()['applicable'] ?? true) === false;
    }

    public static function isVisibilityResult(CheckResult $result): bool
    {
        return ($result->getDetails()['score_group'] ?? '') === 'visibility';
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
            'not_applicable' => $this->getNotApplicableCount(),
            'visibility'  => array_map(fn(CheckResult $r) => [
                'code'    => $r->getCheckCode(),
                'name'    => $r->getCheckName(),
                'status'  => $r->getStatus(),
                'score'   => $r->getDetails()['score'] ?? null,
                'margin'  => $r->getDetails()['score_margin'] ?? null,
                'message' => $r->getMessage(),
            ], $this->getVisibilityResults()),
            'checks'      => array_map(fn(CheckResult $r) => [
                'code'           => $r->getCheckCode(),
                'name'           => $r->getCheckName(),
                'status'         => $r->getStatus(),
                'message'        => $r->getMessage(),
                'recommendation' => $r->getRecommendation(),
                'details'        => $r->getDetails(),
                'weight'         => $r->getWeight(),
                'category'       => $r->getCategory(),
                'severity'       => $r->getSeverity(),
                'fix_command'    => $r->getFixCommand(),
                'applicable'     => !self::isNotApplicable($r),
                'scored'         => !self::isNotApplicable($r) && !self::isVisibilityResult($r),
            ], $this->results),
        ];
    }
}
