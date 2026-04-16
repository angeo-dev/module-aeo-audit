<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\CanonicalChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CanonicalCheckerTest extends TestCase
{
    private Curl|MockObject $curl;
    private CanonicalChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new CanonicalChecker($this->curl);
    }

    public function testPassWhenCanonicalPresent(): void
    {
        $html = '<html><head><link rel="canonical" href="https://example.com/"/></head></html>';
        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
        $this->assertSame('https://example.com/', $result->getDetails()['canonical_url']);
    }

    public function testPassWithAlternateAttributeOrder(): void
    {
        $html = '<html><head><link href="https://example.com/" rel="canonical"/></head></html>';
        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testWarnWhenCanonicalMissing(): void
    {
        $html = '<html><head><title>Store</title></head></html>';
        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertNotEmpty($result->getRecommendation());
    }

    public function testWarnWhenDomainMismatch(): void
    {
        $html = '<html><head><link rel="canonical" href="https://staging.example.com/"/></head></html>';
        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('staging.example.com', $result->getMessage());
    }

    public function testWarnWhenFetchFails(): void
    {
        $this->mockResponse(0, '');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('canonical', $this->checker->getCode());
        $this->assertSame(0.6, $this->checker->getWeight());
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }
}
