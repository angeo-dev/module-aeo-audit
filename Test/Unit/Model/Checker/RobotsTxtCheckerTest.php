<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\RobotsTxtChecker;
use Angeo\AeoAudit\Service\BotRegistry;
use Angeo\AeoAudit\Service\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private RobotsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->checker = new RobotsTxtChecker(
            $this->httpCache,
            $this->urlSampler,
            new BotRegistry(),
            new RobotsTxtParser()
        );
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

    public function testFailWhenSearchCrawlerBlocked(): void
    {
        $body = <<<TXT
User-agent: OAI-SearchBot
Disallow: /

User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'Blocking a search indexer must FAIL');
        $this->assertStringContainsString('OAI-SearchBot', $result->getMessage());
    }

    public function testTrainingOptOutIsNotPunished(): void
    {
        $body = <<<TXT
User-agent: GPTBot
Disallow: /

User-agent: ClaudeBot
Disallow: /

User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        // v4: blocking TRAINING crawlers is a licensing choice — PASS with a note.
        $this->assertTrue(
            $result->isPassed(),
            'Training opt-out must not lower the score, got ' . $result->getStatus()
            . ': ' . $result->getMessage()
        );
        $this->assertContains('GPTBot', $result->getDetails()['blocked_training']);
    }

    public function testWarnWhenFetcherBlocked(): void
    {
        $body = <<<TXT
User-agent: ChatGPT-User
Disallow: /

User-agent: *
Allow: /

Sitemap: https://example.com/sitemap.xml
TXT;
        $this->stubUrl('https://example.com/robots.txt', 200, $body);
        $result = $this->checker->check($this->store);

        // Live-fetch agent blocked → WARN (partially symbolic, but user-hostile)
        $this->assertTrue($result->isWarning(), 'Expected WARN, got ' . $result->getStatus());
        $this->assertStringContainsString('ChatGPT-User', $result->getRecommendation());
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
