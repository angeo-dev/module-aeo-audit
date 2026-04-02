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
    private Curl|MockObject $curlMock;
    private RobotsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->checker  = new RobotsTxtChecker($this->curlMock);
    }

    public function testPassWhenAllBotsAllowed(): void
    {
        $robots = implode("\n", [
            'User-agent: *',
            'Allow: /',
            '',
            'User-agent: GPTBot',
            'Allow: /',
            '',
            'User-agent: ClaudeBot',
            'Allow: /',
            '',
            'Sitemap: https://example.com/sitemap.xml',
        ]);

        $this->mockFetch(200, $robots);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testFailWhenRobotsTxtMissing(): void
    {
        $this->mockFetch(404, '');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testWarnWhenBotBlocked(): void
    {
        $robots = implode("\n", [
            'User-agent: GPTBot',
            'Disallow: /',
        ]);

        $this->mockFetch(200, $robots);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('GPTBot', $result->getMessage());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->checker->getName());
    }

    private function mockFetch(int $status, string $body): void
    {
        $this->curlMock->method('setTimeout')->willReturnSelf();
        $this->curlMock->method('setOption')->willReturnSelf();
        $this->curlMock->method('addHeader')->willReturnSelf();
        $this->curlMock->method('get')->willReturnSelf();
        $this->curlMock->method('getStatus')->willReturn($status);
        $this->curlMock->method('getBody')->willReturn($body);
    }
}
