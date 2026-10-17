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
        ] as $path) {
            $this->stubUrl('https://example.com' . $path, 404, '');
        }
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWhenRestApiPresent(): void
    {
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 200, '{}');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
        $this->assertArrayHasKey('note', $result->getDetails());
    }

    /** 4.3.0: the retired ChatGPT-plugin manifest is no longer probed or required. */
    public function testDoesNotProbeAiPluginJson(): void
    {
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 200, '{}');

        $result = $this->checker->check($this->store);
        $this->assertArrayNotHasKey('ai_plugin_json', $result->getDetails());
        $this->assertStringNotContainsString('ai-plugin', $result->getMessage() . $result->getRecommendation());
    }

    public function testAcceptsRest401AsPresentWithNote(): void
    {
        // 401 = endpoint exists but requires auth
        $this->stubUrl('https://example.com/rest/V1/angeo/product_feeds', 401, '');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
        $this->assertStringContainsString('protected', $result->getMessage());
        $this->assertArrayHasKey('note_auth', $result->getDetails());
    }
}
