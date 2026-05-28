<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\ProductFeedChecker;
use PHPUnit\Framework\TestCase;

class ProductFeedCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private ProductFeedChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new ProductFeedChecker($this->httpCache, $this->urlSampler);
    }

    public function testCategoryIsFeed(): void
    {
        $this->assertSame(CheckerInterface::CATEGORY_FEED, $this->checker->getCategory());
    }

    public function testFailWhenNothingFound(): void
    {
        // All paths 404
        foreach ([
            '/rest/V1/angeo/product_feeds',
            '/angeo/openai_feed/default.csv',
            '/angeo/openai_feed/base.csv',
            '/media/angeo/openai_feed/default.csv',
            '/media/angeo/openai_feed/base.csv',
            '/openai-product-feed.csv',
            '/feeds/products.csv',
            '/feeds/products.json',
            '/feed.json',
            '/catalog/product/feed',
            '/.well-known/ai-plugin.json',
        ] as $path) {
            $this->stubUrl('https://example.com' . $path, 404, '');
        }
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWhenRestApiAndAiPluginPresent(): void
    {
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/ai-plugin.json', 200, '{}');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testWarnsWhenFeedPresentButNoAiPlugin(): void
    {
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 200, '{}');
        $this->stubUrl('https://example.com/.well-known/ai-plugin.json', 404, '');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('ai-plugin.json', $result->getMessage());
    }

    public function testAcceptsRest401AsPresent(): void
    {
        // 401 = endpoint exists but requires auth
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 401, '');
        $this->stubUrl('https://example.com/.well-known/ai-plugin.json', 200, '{}');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
    }
}
