<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use PHPUnit\Framework\TestCase;

class AuditReportTest extends TestCase
{
    public function testScoreCalculation(): void
    {
        $report = new AuditReport('https://example.com', 'default');

        $report->addResult(CheckResult::pass('Check A', 'ok'));           // +2
        $report->addResult(CheckResult::warn('Check B', 'warning'));       // +1
        $report->addResult(CheckResult::fail('Check C', 'failed'));        // +0

        $this->assertSame(3, $report->getScore());
        $this->assertSame(6, $report->getMaxScore());
        $this->assertSame(50, $report->getScorePercent());
        $this->assertSame('Needs Improvement', $report->getScoreLabel());
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

    public function testExcellentLabel(): void
    {
        $report = new AuditReport('https://example.com', 'default');
        for ($i = 0; $i < 10; $i++) {
            $report->addResult(CheckResult::pass("Check $i", 'ok'));
        }
        $this->assertSame('Excellent', $report->getScoreLabel());
        $this->assertSame(100, $report->getScorePercent());
    }
}
