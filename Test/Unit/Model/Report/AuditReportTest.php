<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use PHPUnit\Framework\TestCase;

class AuditReportTest extends TestCase
{
    public function testWeightedScoreAllPass(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report->addResult(CheckResult::pass('B', 'ok', [], 'b', 1.0));

        $this->assertSame(100, $report->getScorePercent());
    }

    public function testWeightedScoreMixed(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        // pass=1.0, warn=0.5*1.0=0.5, fail=0 → earned=1.5, total=3 → 50%
        $report->addResult(CheckResult::pass('A', 'ok',   [], 'a', 1.0));
        $report->addResult(CheckResult::warn('B', 'warn', '', [], 'b', 1.0));
        $report->addResult(CheckResult::fail('C', 'fail', '', [], 'c', 1.0));

        $this->assertSame(50, $report->getScorePercent());
    }

    public function testHigherWeightHasMoreImpact(): void
    {
        $report1 = new AuditReport('https://example.com', 'default');
        $report1->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report1->addResult(CheckResult::fail('B', 'fail', '', [], 'b', 0.5));
        // earned=1.0, total=1.5 → 66%

        $report2 = new AuditReport('https://example.com', 'default');
        $report2->addResult(CheckResult::pass('A', 'ok', [], 'a', 0.5));
        $report2->addResult(CheckResult::fail('B', 'fail', '', [], 'b', 1.0));
        // earned=0.5, total=1.5 → 33%

        $this->assertGreaterThan($report2->getScorePercent(), $report1->getScorePercent());
    }

    public function testScoreLabels(): void
    {
        $cases = [
            [100, 'Excellent'],
            [85,  'Excellent'],
            [80,  'Good'],
            [65,  'Good'],
            [60,  'Needs Improvement'],
            [40,  'Needs Improvement'],
            [39,  'Critical'],
            [0,   'Critical'],
        ];

        foreach ($cases as [$score, $expected]) {
            $report = $this->buildReportWithScore($score);
            $this->assertSame(
                $expected,
                $report->getScoreLabel(),
                "Score {$score}% should be '{$expected}'"
            );
        }
    }

    public function testCountByStatus(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('A', 'ok'));
        $report->addResult(CheckResult::pass('B', 'ok'));
        $report->addResult(CheckResult::warn('C', 'warn'));
        $report->addResult(CheckResult::fail('D', 'fail'));

        $this->assertSame(2, $report->getPassCount());
        $this->assertSame(1, $report->getWarnCount());
        $this->assertSame(1, $report->getFailCount());
    }

    public function testToArrayStructure(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('Check A', 'ok', [], 'check_a', 1.0));

        $data = $report->toArray();

        $this->assertArrayHasKey('store_code', $data);
        $this->assertArrayHasKey('store_url', $data);
        $this->assertArrayHasKey('score', $data);
        $this->assertArrayHasKey('label', $data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertCount(1, $data['checks']);
        $this->assertSame('check_a', $data['checks'][0]['code']);
        $this->assertSame(1.0, $data['checks'][0]['weight']);
    }

    public function testEmptyReportReturnsZeroScore(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $this->assertSame(0, $report->getScorePercent());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a report that achieves approximately the given score percent
     * using equal-weight checks.
     */
    private function buildReportWithScore(int $targetPercent): AuditReport
    {
        $report = new AuditReport('https://example.com', 'default');

        // Use 10 checks of weight 1.0 each; fill pass/fail to hit target
        // PASS=1.0 weight, WARN=0.5 weight — use only pass/fail for determinism
        $passCount = (int) round($targetPercent / 10);
        $failCount = 10 - $passCount;

        for ($i = 0; $i < $passCount; $i++) {
            $report->addResult(CheckResult::pass("Check {$i}", 'ok', [], "c{$i}", 1.0));
        }
        for ($i = $passCount; $i < $passCount + $failCount; $i++) {
            $report->addResult(CheckResult::fail("Check {$i}", 'fail', '', [], "c{$i}", 1.0));
        }

        return $report;
    }
}
