<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;
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
        $report->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report->addResult(CheckResult::warn('B', 'warn', '', [], 'b', 1.0));
        $report->addResult(CheckResult::fail('C', 'fail', '', [], 'c', 1.0));

        // pass=1.0, warn=0.5, fail=0 → earned=1.5, total=3 → 50%
        $this->assertSame(50, $report->getScorePercent());
    }

    public function testHigherWeightHasMoreImpact(): void
    {
        $a = new AuditReport('https://example.com', 'default');
        $a->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $a->addResult(CheckResult::fail('B', 'fail', '', [], 'b', 0.5));
        // earned=1.0, total=1.5 → 66

        $b = new AuditReport('https://example.com', 'default');
        $b->addResult(CheckResult::pass('A', 'ok', [], 'a', 0.5));
        $b->addResult(CheckResult::fail('B', 'fail', '', [], 'b', 1.0));
        // earned=0.5, total=1.5 → 33

        $this->assertGreaterThan($b->getScorePercent(), $a->getScorePercent());
    }

    public function testScoreLabels(): void
    {
        $cases = [
            [85, 'Excellent'],
            [65, 'Good'],
            [40, 'Needs Improvement'],
            [10, 'Critical'],
        ];
        foreach ($cases as [$threshold, $label]) {
            $report = new AuditReport('https://e.com', 'default');
            $report->addResult(CheckResult::pass('A', 'ok', [], 'a', $threshold / 100.0));
            $report->addResult(CheckResult::fail('B', 'no', '', [], 'b', 1.0 - $threshold / 100.0));
            // earned = $threshold/100, total = 1.0 → score = $threshold
            $this->assertSame($label, $report->getScoreLabel(), "Threshold $threshold should be $label");
        }
    }

    public function testCounts(): void
    {
        $r = new AuditReport('https://e.com', 'default');
        $r->addResult(CheckResult::pass('p1', 'ok', [], 'p1'));
        $r->addResult(CheckResult::pass('p2', 'ok', [], 'p2'));
        $r->addResult(CheckResult::warn('w1', 'w', '', [], 'w1'));
        $r->addResult(CheckResult::fail('f1', 'f', '', [], 'f1'));

        $this->assertSame(2, $r->getPassCount());
        $this->assertSame(1, $r->getWarnCount());
        $this->assertSame(1, $r->getFailCount());
    }

    /**
     * 4.6.0 regression: on demo.angeo.dev the CLI printed "Pass 13, Warn 9,
     * n/a 1" for 22 rows. The not-applicable A2A row (stored as a pass) was
     * counted twice and the measured-visibility row was counted as a
     * readiness warning. Every row must land in exactly one bucket.
     */
    public function testEveryResultIsCountedOnceAndVisibilityIsNotAReadinessWarning(): void
    {
        $r = new AuditReport('https://e.com', 'default');
        $r->addResult(CheckResult::pass('robots', 'ok', [], 'robots', 1.0));
        $r->addResult(CheckResult::pass('info', 'ok', [], 'agent_discovery', 0.0));
        $r->addResult(CheckResult::warn('llms', 'w', '', [], 'llms_txt', 0.5));
        $r->addResult(CheckResult::pass('A2A', 'n/a', ['applicable' => false], 'agent_card', 0.5));
        $r->addResult(CheckResult::warn('Brand', 'score 48', '', ['score_group' => 'visibility'], 'brand', 1.0));

        $this->assertSame(2, $r->getPassCount());
        $this->assertSame(1, $r->getWarnCount());
        $this->assertSame(0, $r->getFailCount());
        $this->assertSame(1, $r->getNotApplicableCount());
        $this->assertCount(1, $r->getVisibilityResults());
        $this->assertSame(
            count($r->getResults()),
            $r->getPassCount() + $r->getWarnCount() + $r->getFailCount()
                + $r->getNotApplicableCount() + count($r->getVisibilityResults())
        );

        $array = $r->toArray();
        $this->assertSame([2, 1, 0, 1], [$array['pass'], $array['warn'], $array['fail'], $array['not_applicable']]);

        // The raw count is still there for anyone who needs every row.
        $this->assertSame(3, $r->countByStatus(CheckerInterface::STATUS_PASS));
    }

    public function testToArrayIncludesV3Fields(): void
    {
        $r = new AuditReport('https://example.com', 'default');
        $r->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));

        $arr = $r->toArray();
        $this->assertSame('default', $arr['store_code']);
        $this->assertSame('https://example.com', $arr['store_url']);
        $this->assertArrayHasKey('checks', $arr);
        $this->assertCount(1, $arr['checks']);

        $check = $arr['checks'][0];
        $this->assertArrayHasKey('category', $check);
        $this->assertArrayHasKey('severity', $check);
        $this->assertArrayHasKey('fix_command', $check);
        $this->assertSame(CheckerInterface::CATEGORY_TECHNICAL, $check['category']);
        $this->assertSame(CheckerInterface::SEVERITY_CRITICAL, $check['severity']);
    }

    public function testEmptyReportScoresZero(): void
    {
        $r = new AuditReport('https://e.com', 'default');
        $this->assertSame(0, $r->getScorePercent());
        $this->assertSame('Critical', $r->getScoreLabel());
    }

    /** 4.3.0: a signal marked applicable=false is left out of the score, not counted as a pass. */
    public function testNotApplicableIsExcludedFromScore(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report->addResult(CheckResult::fail('B', 'fail', '', [], 'b', 1.0));
        $report->addResult(CheckResult::pass('C', 'n/a', ['applicable' => false], 'c', 1.0));

        // Only A and B count: earned=1.0, total=2.0 → 50%
        $this->assertSame(50, $report->getScorePercent());
        $this->assertSame(1, $report->getNotApplicableCount());
    }

    /** 4.3.0: measured visibility is reported apart from readiness. */
    public function testVisibilityResultIsReportedSeparately(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report->addResult(CheckResult::fail('Brand', 'score 10/100', '', ['score_group' => 'visibility', 'score' => 10], 'brand', 1.0));

        $this->assertSame(100, $report->getScorePercent());
        $this->assertCount(1, $report->getVisibilityResults());
        $array = $report->toArray();
        $this->assertSame(10, $array['visibility'][0]['score']);
        $this->assertFalse($array['checks'][1]['scored']);
    }

    /** A switched-off visibility add-on is neither scored nor listed as visibility. */
    public function testDisabledVisibilityIsNotListed(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        $report->addResult(CheckResult::pass('A', 'ok', [], 'a', 1.0));
        $report->addResult(CheckResult::pass('Brand', 'off', ['score_group' => 'visibility', 'applicable' => false], 'brand', 1.0));

        $this->assertSame(100, $report->getScorePercent());
        $this->assertCount(0, $report->getVisibilityResults());
    }
}
