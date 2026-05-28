<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\LlmsTxtChecker;
use PHPUnit\Framework\TestCase;

class LlmsTxtCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private LlmsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new LlmsTxtChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailWhenMissing(): void
    {
        $this->stubUrl('https://example.com/llms.txt', 404, '');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWithMinimalValidFile(): void
    {
        $body = <<<TXT
# Example Store

A brief description of Example Store, selling widgets since 2020.

## Products

- [Widget A](https://example.com/widget-a)
- [Widget B](https://example.com/widget-b)
TXT;
        $this->stubUrl('https://example.com/llms.txt', 200, $body);
        $result = $this->checker->check($this->store);
        // Passes with possible minor warning about metadata, but should be PASS or WARN
        $this->assertNotTrue($result->isFailed(), 'Got FAIL: ' . $result->getMessage());
    }

    public function testFailWhenMissingH1(): void
    {
        $body = <<<TXT
Just description without an H1 title

## Section
- [Link](https://example.com/page)
TXT;
        $this->stubUrl('https://example.com/llms.txt', 200, $body);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('H1', $result->getRecommendation());
    }

    public function testCrossHostLinksDetected(): void
    {
        $body = <<<TXT
# Example Store

A description here.

## Links
- [Other Site](https://other.com/page)
- [Our Page](https://example.com/page)
TXT;
        $this->stubUrl('https://example.com/llms.txt', 200, $body);
        // Link checks
        $this->stubUrl('https://other.com/page', 200, 'ok');
        $this->stubUrl('https://example.com/page', 200, 'ok');
        $this->stubUrl('https://example.com/llms-full.txt', 404, '');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $combined = $result->getRecommendation();
        $this->assertStringContainsString('different host', $combined);
    }
}
