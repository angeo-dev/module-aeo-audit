<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\LlmsJsonlChecker;
use PHPUnit\Framework\TestCase;

class LlmsJsonlCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private LlmsJsonlChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new LlmsJsonlChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailWhenMissing(): void
    {
        $this->stubUrl('https://example.com/llms.jsonl', 404, '');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWithValidJsonlRecords(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = json_encode([
                'url'   => "https://example.com/product-$i",
                'title' => "Product $i",
                'price' => 19.99,
                'sku'   => "SKU-$i",
            ]);
        }
        $body = implode("\n", $lines);
        $this->stubUrl('https://example.com/llms.jsonl', 200, $body);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testFailsOnInvalidJsonLines(): void
    {
        $body = "{\"url\":\"x\",\"title\":\"y\"}\nNOT JSON\n{\"url\":\"z\",\"title\":\"w\"}";
        $this->stubUrl('https://example.com/llms.jsonl', 200, $body);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('invalid JSON', $result->getMessage()
            . ' ' . $result->getRecommendation());
    }

    public function testFailsWhenUrlFieldMissing(): void
    {
        $lines = [];
        for ($i = 1; $i <= 6; $i++) {
            $lines[] = json_encode(['title' => "P$i", 'price' => $i]);
        }
        $body = implode("\n", $lines);
        $this->stubUrl('https://example.com/llms.jsonl', 200, $body);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('url', $result->getMessage()
            . ' ' . $result->getRecommendation());
    }

    public function testWarnsOnTooFewRecords(): void
    {
        $body = json_encode(['url' => 'x', 'title' => 'y', 'price' => 1]);
        $this->stubUrl('https://example.com/llms.jsonl', 200, $body);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
    }
}
