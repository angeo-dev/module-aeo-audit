<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\WafRealityChecker;
use Angeo\AeoAudit\Service\BotRegistry;
use Angeo\AeoAudit\Service\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class WafRealityCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private WafRealityChecker $checker;

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> keyed by "UA-token|url" */
    private array $uaResponses = [];

    protected function setUp(): void
    {
        $this->bootCheckerMocks();

        // getAs(url, ua) — resolve by which bot token appears in the UA string.
        $this->httpCache->method('getAs')->willReturnCallback(
            function (string $url, string $userAgent): array {
                foreach ($this->uaResponses as $key => $response) {
                    [$token, $stubUrl] = explode('|', $key, 2);
                    if ($stubUrl === $url && stripos($userAgent, $token) !== false) {
                        return $response;
                    }
                }
                return [200, '<html>ok</html>', []];
            }
        );

        $this->checker = new WafRealityChecker(
            $this->httpCache,
            $this->urlSampler,
            new BotRegistry(),
            new RobotsTxtParser()
        );
    }

    private function stubUa(string $uaToken, string $url, int $status, string $body = ''): void
    {
        $this->uaResponses[$uaToken . '|' . $url] = [$status, $body, []];
    }

    public function testPassWhenEdgeMatchesRobots(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html>store</html>');
        $this->stubUrl('https://example.com/robots.txt', 200, "User-agent: *\nAllow: /\n");
        // All UA probes fall through to the default 200.

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isPassed(), $result->getMessage());
        $this->assertSame([], $result->getDetails()['mismatches']);
    }

    public function testWarnWhenRobotsAllowsButEdgeBlocks(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html>store</html>');
        $this->stubUrl('https://example.com/robots.txt', 200, "User-agent: *\nAllow: /\n");
        $this->stubUa('OAI-SearchBot', 'https://example.com/', 403);

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning(), $result->getMessage());
        $this->assertArrayHasKey('OAI-SearchBot', $result->getDetails()['mismatches']);
        $this->assertStringContainsString('robots.txt allows', $result->getMessage());
    }

    public function testChallengePageBodyIsDetectedAsBlock(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html>store</html>');
        $this->stubUrl('https://example.com/robots.txt', 200, "User-agent: *\nAllow: /\n");
        // HTTP 200 but a Cloudflare JS-challenge page — still a block.
        $this->stubUa('PerplexityBot', 'https://example.com/', 200, '<title>Just a moment...</title>');

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertArrayHasKey('PerplexityBot', $result->getDetails()['mismatches']);
    }

    public function testRobotsOptOutMakesEdgeBlockConsistent(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html>store</html>');
        $this->stubUrl(
            'https://example.com/robots.txt',
            200,
            "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n"
        );
        // Edge also blocks GPTBot — consistent with declared policy, not a mismatch.
        $this->stubUa('GPTBot', 'https://example.com/', 403);

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isPassed(), $result->getMessage());
        $this->assertContains('GPTBot', $result->getDetails()['robots_opted_out']);
    }

    public function testWarnWhenBaselineItselfIsBlocked(): void
    {
        $this->stubUrl('https://example.com/', 403, 'Forbidden');

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('Baseline', $result->getMessage());
    }

    public function testMissingRobotsTxtMeansEverythingImplicitlyAllowed(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html>store</html>');
        $this->stubUrl('https://example.com/robots.txt', 404, '');
        $this->stubUa('ClaudeBot', 'https://example.com/', 403);

        $result = $this->checker->check($this->store);

        // No robots.txt = no declared restrictions, so an edge block IS a mismatch.
        $this->assertTrue($result->isWarning());
        $this->assertArrayHasKey('ClaudeBot', $result->getDetails()['mismatches']);
    }

    public function testMetadata(): void
    {
        $this->assertSame('waf_reality', $this->checker->getCode());
        $this->assertSame(0.9, $this->checker->getWeight());
    }
}
