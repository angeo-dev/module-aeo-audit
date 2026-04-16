<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\RobotsTxtChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RobotsTxtCheckerTest extends TestCase
{
    private Curl|MockObject $curl;
    private RobotsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new RobotsTxtChecker($this->curl);
    }

    public function testPassWhenAllBotsExplicitlyAllowed(): void
    {
        $this->mockResponse(200, "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml\n");

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
        $this->assertSame('robots_txt', $result->getCheckCode());
        $this->assertSame(1.0, $result->getWeight());
    }

    public function testFailWhenRobotsTxtMissing(): void
    {
        $this->mockResponse(404, '');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertStringContainsString('404', $result->getMessage());
    }

    public function testFailWhenCriticalBotBlocked(): void
    {
        $robots = "User-agent: GPTBot\nDisallow: /\n\nUser-agent: OAI-SearchBot\nDisallow: /\n";
        $this->mockResponse(200, $robots);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertStringContainsString('GPTBot', $result->getMessage());
    }

    public function testWarnWhenNonCriticalBotBlocked(): void
    {
        $robots = "User-agent: *\nAllow: /\n\nUser-agent: cohere-ai\nDisallow: /\n\nSitemap: https://example.com/sitemap.xml\n";
        $this->mockResponse(200, $robots);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('cohere-ai', $result->getMessage());
    }

    public function testWarnWhenSitemapNotDeclared(): void
    {
        $this->mockResponse(200, "User-agent: *\nAllow: /\n");

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('sitemap', strtolower($result->getMessage()));
    }

    public function testWildcardBlockWithExplicitAllowForBot(): void
    {
        $robots = "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n\nUser-agent: OAI-SearchBot\nAllow: /\n";
        $this->mockResponse(200, $robots);

        $result = $this->checker->check('https://example.com');

        // GPTBot and OAI-SearchBot explicitly allowed, others may be blocked — should be WARN not FAIL
        $this->assertNotSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testInlineCommentsAreStripped(): void
    {
        $robots = "User-agent: * # all bots\nAllow: / # allow everything\nSitemap: https://example.com/sitemap.xml\n";
        $this->mockResponse(200, $robots);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('robots_txt', $this->checker->getCode());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }
}
