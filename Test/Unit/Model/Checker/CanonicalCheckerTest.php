<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\CanonicalChecker;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CanonicalCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private CanonicalChecker $checker;
    private const PRODUCT_URL = 'https://example.com/product';

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->urlSampler->method('getSampleProductUrl')->willReturn(self::PRODUCT_URL);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $singleStore  = $this->createMock(StoreInterface::class);
        $storeManager->method('getStores')->willReturn([$singleStore]);

        $this->checker = new CanonicalChecker($this->httpCache, $this->urlSampler, $storeManager);
    }

    public function testFailWhenNoCanonical(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, '<html><head></head><body></body></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWithCleanCanonical(): void
    {
        $html = <<<HTML
<html><head>
    <link rel="canonical" href="https://example.com/product">
    <meta property="og:url" content="https://example.com/product">
    <script type="application/ld+json">{"@type":"Product","name":"X","url":"https://example.com/product"}</script>
</head><body></body></html>
HTML;
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testWarnsOnHttpCanonical(): void
    {
        $html = '<html><head><link rel="canonical" href="http://example.com/product"></head></html>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('HTTP', $result->getRecommendation());
    }

    public function testWarnsOnOgUrlMismatch(): void
    {
        $html = '<html><head>'
            . '<link rel="canonical" href="https://example.com/product">'
            . '<meta property="og:url" content="https://example.com/different">'
            . '</head></html>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('og:url', $result->getRecommendation());
    }

    public function testWarnsOnHreflangMissingWhenMultiStore(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->createMock(StoreInterface::class),
            $this->createMock(StoreInterface::class),
        ]);
        $checker = new CanonicalChecker($this->httpCache, $this->urlSampler, $storeManager);

        $html = '<html><head>'
            . '<link rel="canonical" href="https://example.com/product">'
            . '<meta property="og:url" content="https://example.com/product">'
            . '</head></html>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('hreflang', $result->getRecommendation());
    }
}
