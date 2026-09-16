<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\AgentsMdChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\AeoAudit\Model\Checker\AgentsMdChecker
 */
class AgentsMdCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private AgentsMdChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->checker = new AgentsMdChecker($this->httpCache, $this->urlSampler);
    }

    public function testMissingFileFails(): void
    {
        $this->stubUrl('https://example.com/agents.md', 404, '');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testCompleteFilePasses(): void
    {
        $this->stubUrl(
            'https://example.com/agents.md',
            200,
            $this->completeFile(),
            ['content-type' => 'text/markdown; charset=utf-8']
        );
        $this->stubUrl('https://example.com/sitemap_agentic_discovery.xml', 200, '<urlset/>');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testMissingPoliciesOnlyWarns(): void
    {
        // A store may publish agents.md before writing its policy pages. That
        // is incomplete, not broken — it must not fail the audit.
        // Long enough (> 120 bytes) not to count as a placeholder file.
        $body = "# Demo Store\n\nIndependent shop selling handmade ceramics, made in small batches.\n\n"
            . "See https://example.com/llms.txt and https://example.com/about\n";
        $this->stubUrl('https://example.com/agents.md', 200, $body, ['content-type' => 'text/markdown']);
        $this->stubUrl('https://example.com/sitemap_agentic_discovery.xml', 200, '<urlset/>');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        self::assertStringContainsString('delivery', $result->getRecommendation());
    }

    public function testPlaceholderFileFails(): void
    {
        $this->stubUrl('https://example.com/agents.md', 200, "# Shop\n", ['content-type' => 'text/markdown']);
        $this->stubUrl('https://example.com/sitemap_agentic_discovery.xml', 404, '');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testHtmlContentTypeIsFlagged(): void
    {
        // Usually means a CMS page is answering /agents.md instead of a file.
        $this->stubUrl(
            'https://example.com/agents.md',
            200,
            $this->completeFile(),
            ['content-type' => 'text/html; charset=utf-8']
        );
        $this->stubUrl('https://example.com/sitemap_agentic_discovery.xml', 200, '<urlset/>');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        self::assertStringContainsString('text/html', $result->getRecommendation());
    }

    private function completeFile(): string
    {
        return "# Demo Store\n\n"
            . "> Independent shop selling ceramics.\n\n"
            . "## Key pages\n\n"
            . "- **Delivery:** https://example.com/shipping-policy\n"
            . "- **Returns:** https://example.com/returns\n"
            . "- **Privacy:** https://example.com/privacy-policy\n\n"
            . "## Machine-readable surfaces\n\n"
            . "- llms.txt: https://example.com/llms.txt\n";
    }
}
