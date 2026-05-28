<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function testPassFactory(): void
    {
        $r = CheckResult::pass('Robots', 'all good', ['x' => 1], 'robots', 1.0);
        $this->assertSame(CheckerInterface::STATUS_PASS, $r->getStatus());
        $this->assertTrue($r->isPassed());
        $this->assertFalse($r->isWarning());
        $this->assertFalse($r->isFailed());
        $this->assertSame(['x' => 1], $r->getDetails());
        $this->assertSame(1.0, $r->getWeightedScore());
    }

    public function testWarnFactoryHasFixCommand(): void
    {
        $r = CheckResult::warn('Llms', 'partial', 'install thing', [], 'llms', 1.0, 'composer require x');
        $this->assertSame(CheckerInterface::STATUS_WARN, $r->getStatus());
        $this->assertTrue($r->isWarning());
        $this->assertSame('composer require x', $r->getFixCommand());
        $this->assertSame(0.5, $r->getWeightedScore());
    }

    public function testFailFactoryHasZeroScore(): void
    {
        $r = CheckResult::fail('Sitemap', 'broken', 'fix it', [], 'sitemap', 1.0);
        $this->assertSame(CheckerInterface::STATUS_FAIL, $r->getStatus());
        $this->assertTrue($r->isFailed());
        $this->assertSame(0.0, $r->getWeightedScore());
    }

    /**
     * @dataProvider severityProvider
     */
    public function testSeverityDerivedFromWeight(float $weight, string $expected): void
    {
        $r = CheckResult::pass('X', 'ok', [], 'x', $weight);
        $this->assertSame($expected, $r->getSeverity());
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function severityProvider(): array
    {
        return [
            'critical-1.0' => [1.0, CheckerInterface::SEVERITY_CRITICAL],
            'critical-0.9' => [0.9, CheckerInterface::SEVERITY_CRITICAL],
            'critical-0.8' => [0.8, CheckerInterface::SEVERITY_CRITICAL],
            'important-0.7' => [0.7, CheckerInterface::SEVERITY_IMPORTANT],
            'important-0.6' => [0.6, CheckerInterface::SEVERITY_IMPORTANT],
            'info-0.5'     => [0.5, CheckerInterface::SEVERITY_INFORMATIONAL],
            'info-0.0'     => [0.0, CheckerInterface::SEVERITY_INFORMATIONAL],
        ];
    }

    public function testToArrayShape(): void
    {
        $r = CheckResult::warn('Sitemap', 'partial', 'fix', ['url' => 'x'], 'sitemap', 0.8, 'composer require x');
        $arr = $r->toArray();
        $this->assertSame('Sitemap', $arr['check_name']);
        $this->assertSame('sitemap', $arr['check_code']);
        $this->assertSame(CheckerInterface::STATUS_WARN, $arr['status']);
        $this->assertSame(0.8, $arr['weight']);
        $this->assertSame('composer require x', $arr['fix_command']);
        $this->assertSame(CheckerInterface::SEVERITY_CRITICAL, $arr['severity']);
        $this->assertSame(CheckerInterface::CATEGORY_TECHNICAL, $arr['category']);
        $this->assertSame(['url' => 'x'], $arr['details']);
    }

    public function testExplicitCategoryAndSeverity(): void
    {
        $r = CheckResult::pass('X', 'ok', [], 'x', 0.5,
            CheckerInterface::CATEGORY_EXTERNAL_API,
            CheckerInterface::SEVERITY_CRITICAL
        );
        $this->assertSame(CheckerInterface::CATEGORY_EXTERNAL_API, $r->getCategory());
        $this->assertSame(CheckerInterface::SEVERITY_CRITICAL, $r->getSeverity());
    }
}
