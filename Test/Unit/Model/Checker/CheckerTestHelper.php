<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Shared scaffolding for checker unit tests.
 *
 * Each checker test wants:
 *  - A mocked HttpCache with stubbed responses for specific URLs
 *  - A mocked StoreUrlSampler returning the URLs the checker will sample
 *  - A mocked StoreInterface
 *
 * This trait builds those mocks via PHPUnit's createMock helper and exposes
 * convenience methods to register stubbed HTTP responses.
 */
trait CheckerTestHelper
{
    /** @var HttpCache&MockObject */
    protected HttpCache $httpCache;

    /** @var StoreUrlSampler&MockObject */
    protected StoreUrlSampler $urlSampler;

    /** @var StoreInterface&MockObject */
    protected StoreInterface $store;

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> */
    private array $stubbedResponses = [];

    /**
     * Set up the standard mocks. Call from setUp().
     */
    protected function bootCheckerMocks(string $baseUrl = 'https://example.com'): void
    {
        $this->httpCache  = $this->createMock(HttpCache::class);
        $this->urlSampler = $this->createMock(StoreUrlSampler::class);
        $this->store      = $this->createMock(StoreInterface::class);

        $this->urlSampler->method('getBaseUrl')->willReturn($baseUrl);
        $this->store->method('getId')->willReturn(1);
        $this->store->method('getCode')->willReturn('default');

        $this->httpCache->method('get')->willReturnCallback(
            fn(string $url) => $this->stubbedResponses[$url] ?? [0, '', []]
        );
        $this->httpCache->method('status')->willReturnCallback(
            fn(string $url) => ($this->stubbedResponses[$url] ?? [0])[0]
        );
    }

    /**
     * @param array<string, string> $headers
     */
    protected function stubUrl(string $url, int $status, string $body, array $headers = []): void
    {
        $this->stubbedResponses[$url] = [$status, $body, $headers];
    }
}
