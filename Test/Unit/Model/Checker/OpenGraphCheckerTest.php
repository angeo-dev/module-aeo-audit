<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\OpenGraphChecker;
use PHPUnit\Framework\TestCase;

class OpenGraphCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private OpenGraphChecker $checker;
    private const PRODUCT_URL = 'https://example.com/product';

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->urlSampler->method('getSampleProductUrl')->willReturn(self::PRODUCT_URL);
        $this->checker = new OpenGraphChecker($this->httpCache, $this->urlSampler);
    }

    public function testWarnsWhenNoProductsAvailable(): void
    {
        $sampler = $this->createMock(\Angeo\AeoAudit\Service\StoreUrlSampler::class);
        $sampler->method('getBaseUrl')->willReturn('https://example.com');
        $sampler->method('getSampleProductUrl')->willReturn(null);
        $checker = new OpenGraphChecker($this->httpCache, $sampler);

        $result = $checker->check($this->store);
        $this->assertTrue($result->isWarning());
    }

    public function testWarnsOnMissingTags(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, '<html><head></head><body></body></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('Missing', $result->getMessage());
    }

    public function testPassWithAllTagsAndLongDescription(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="My Product">'
            . '<meta property="og:type" content="product">'
            . '<meta property="og:image" content="https://example.com/img.jpg">'
            . '<meta property="og:url" content="https://example.com/product">'
            . '<meta property="og:description" content="A really excellent product that you should definitely consider buying today">'
            . '</head><body></body></html>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testWarnsOnShortDescription(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="My Product">'
            . '<meta property="og:type" content="product">'
            . '<meta property="og:image" content="https://example.com/img.jpg">'
            . '<meta property="og:url" content="https://example.com/product">'
            . '<meta property="og:description" content="Short.">'
            . '</head><body></body></html>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('short', $result->getMessage());
    }
}
