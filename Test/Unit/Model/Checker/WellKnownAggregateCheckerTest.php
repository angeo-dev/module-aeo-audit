<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\WellKnownAggregateChecker;
use PHPUnit\Framework\TestCase;

class WellKnownAggregateCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private WellKnownAggregateChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new WellKnownAggregateChecker($this->httpCache, $this->urlSampler);
    }

    public function testWarnsWhenNothingPresent(): void
    {
        $endpoints = [
            '/.well-known/ucp',
            '/.well-known/ai-plugin.json',
            '/.well-known/security.txt',
            '/.well-known/mcp',
        ];
        foreach ($endpoints as $p) {
            $this->stubUrl('https://example.com' . $p, 404, '');
        }

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertSame(0, $result->getDetails()['found_count']);
        $this->assertSame(4, $result->getDetails()['total_count']);
    }

    public function testPartialPresenceIsWarning(): void
    {
        $this->stubUrl('https://example.com/.well-known/ucp', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/ai-plugin.json', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/security.txt', 404, '');
        $this->stubUrl('https://example.com/.well-known/mcp', 404, '');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertSame(2, $result->getDetails()['found_count']);
    }

    public function testAllPresentPasses(): void
    {
        $this->stubUrl('https://example.com/.well-known/ucp', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/ai-plugin.json', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/security.txt', 200, '');
        $this->stubUrl('https://example.com/.well-known/mcp', 200, '{}');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Expected PASS, got ' . $result->getStatus());
    }
}
