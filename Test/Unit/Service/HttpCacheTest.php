<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Service;

use Angeo\AeoAudit\Service\HttpCache;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpCacheTest extends TestCase
{
    private Curl&MockObject $curl;
    private HttpCache       $cache;

    protected function setUp(): void
    {
        $this->curl  = $this->createMock(Curl::class);
        $this->cache = new HttpCache($this->curl);
    }

    public function testSecondCallToSameUrlIsCached(): void
    {
        $this->curl->expects($this->once())->method('get')->with('https://e.com/x');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('hello');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        [$status1, $body1] = $this->cache->get('https://e.com/x');
        [$status2, $body2] = $this->cache->get('https://e.com/x');

        $this->assertSame(200, $status1);
        $this->assertSame(200, $status2);
        $this->assertSame('hello', $body1);
        $this->assertSame('hello', $body2);

        $stats = $this->cache->getStats();
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(1, $stats['hits']);
    }

    public function testDifferentUrlsCachedSeparately(): void
    {
        $this->curl->expects($this->exactly(2))->method('get');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('content');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        $this->cache->get('https://e.com/a');
        $this->cache->get('https://e.com/b');

        $stats = $this->cache->getStats();
        $this->assertSame(2, $stats['misses']);
        $this->assertSame(0, $stats['hits']);
    }

    public function testCurlExceptionReturnsZeroStatus(): void
    {
        $this->curl->method('get')->willThrowException(new \RuntimeException('connection failed'));

        [$status, $body, $headers] = $this->cache->get('https://broken.example/');

        $this->assertSame(0, $status);
        $this->assertSame('', $body);
        $this->assertSame([], $headers);
    }

    public function testResetClearsCache(): void
    {
        $this->curl->method('get');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('x');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        $this->cache->get('https://e.com/a');
        $this->cache->reset();
        $stats = $this->cache->getStats();
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0, $stats['hits']);
    }

    public function testStatusReturnsCodeOnly(): void
    {
        $this->curl->method('get');
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->method('getBody')->willReturn('not found');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        $this->assertSame(404, $this->cache->status('https://e.com/missing'));
    }
}
