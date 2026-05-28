<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\RobotsTxtChecker;
use PHPUnit\Framework\TestCase;

class RobotsTxtCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private RobotsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->checker = new RobotsTxtChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailWhenRobotsMissing(): void
    {
        $this->stubUrl('https://example.com/robots.txt', 404, '');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWhenWildcardAllowsAndBotsOk(): void
    {
        $body = <<<TXT
User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        // Wildcard allow, no AI bot blocked, no syntax issues, sitemap with HTTPS — PASS
        $this->assertTrue($result->isPassed(), 'Expected PASS, got ' . $result->getStatus()
            . ' message: ' . $result->getMessage());
    }

    public function testFailWhenCriticalBotBlocked(): void
    {
        $body = <<<TXT
User-agent: GPTBot
Disallow: /

User-agent: *
Allow: /
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'GPTBot disallow should FAIL');
        $this->assertStringContainsString('GPTBot', $result->getMessage());
    }

    public function testWarnWhenNonCriticalBotBlocked(): void
    {
        $body = <<<TXT
User-agent: ClaudeBot
Disallow: /

User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        // ClaudeBot is non-critical → WARN
        $this->assertTrue($result->isWarning(), 'Expected WARN, got ' . $result->getStatus());
    }

    public function testSyntaxIssueVersionedUaIsReported(): void
    {
        $body = <<<TXT
User-agent: GPTBot/1.0
Allow: /

User-agent: *
Allow: /
Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        $this->assertStringContainsString('version', $result->getRecommendation()
            . ' ' . $result->getMessage());
    }

    public function testHttpSitemapIsFlagged(): void
    {
        $body = <<<TXT
User-agent: *
Allow: /

Sitemap: http://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('HTTP', $result->getRecommendation()
            . ' ' . $result->getMessage());
    }

    public function testCheckerMetadata(): void
    {
        $this->assertSame('robots_txt', $this->checker->getCode());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertSame(CheckerInterface::CATEGORY_TECHNICAL, $this->checker->getCategory());
        $this->assertSame(CheckerInterface::SEVERITY_CRITICAL, $this->checker->getSeverity());
        $this->assertStringContainsString('composer require', $this->checker->getFixCommand());
    }
}
