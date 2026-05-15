<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\ProductFeedChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductFeedCheckerTest extends TestCase
{
    /** @var Curl&MockObject */
    private Curl|MockObject $curl;
    /** @var ProductFeedChecker */
    private ProductFeedChecker $checker;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->checker = new ProductFeedChecker($this->curl);
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('ai_product_feed', $this->checker->getCode());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
        $this->assertStringContainsString('openai-product-feed', $this->checker->getFixCommand());
    }

    public function testFailWhenNoFeedFound(): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->method('getBody')->willReturn('');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertStringContainsString('No AI product feed', $result->getMessage());
    }

    public function testPassWhenRestApiAndAiPluginFound(): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }
}
