<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Service;

use Angeo\AeoAudit\Service\HttpCache;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HttpCacheTest extends TestCase
{
    private Curl&MockObject        $curl;
    private CurlFactory&MockObject $curlFactory;
    private HttpCache              $cache;

    /** @var array<int, array{0: string, 1: mixed}> Captured curl options per request */
    private array $capturedOptions = [];

    protected function setUp(): void
    {
        $this->capturedOptions = [];
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('setOption')->willReturnCallback(
            function ($name, $value) {
                $this->capturedOptions[] = [$name, $value];
            }
        );

        $this->curlFactory = $this->createMock(CurlFactory::class);
        $this->curlFactory->method('create')->willReturn($this->curl);

        $this->cache = new HttpCache($this->curlFactory);
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

    /**
     * Regression test for the 3.1.0 security fixes: TLS verification must
     * never be disabled and only web protocols may be used (incl. redirects).
     */
    public function testTlsVerificationIsNeverDisabledAndProtocolsAreRestricted(): void
    {
        $this->curl->method('get');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('ok');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        $this->cache->get('https://e.com/secure');

        $optionNames = array_column($this->capturedOptions, 0);
        $this->assertNotContains(CURLOPT_SSL_VERIFYPEER, $optionNames, 'TLS peer verification must not be touched');
        $this->assertNotContains(CURLOPT_SSL_VERIFYHOST, $optionNames, 'TLS host verification must not be touched');

        $byName = [];
        foreach ($this->capturedOptions as [$name, $value]) {
            $byName[$name] = $value;
        }
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $byName[CURLOPT_PROTOCOLS] ?? null);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $byName[CURLOPT_REDIR_PROTOCOLS] ?? null);
    }

    public function testPostSendsPayloadWithHeadersAndIsNotCached(): void
    {
        $this->curl->expects($this->exactly(2))->method('post')
            ->with('https://api.example/v1', '{"a":1}');
        $this->curl->expects($this->atLeastOnce())->method('addHeader');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"ok":true}');
        if (method_exists($this->curl, 'getHeaders')) {
            $this->curl->method('getHeaders')->willReturn([]);
        }

        [$status1] = $this->cache->post('https://api.example/v1', '{"a":1}', ['X-Test' => 'yes']);
        [$status2] = $this->cache->post('https://api.example/v1', '{"a":1}', ['X-Test' => 'yes']);

        $this->assertSame(200, $status1);
        $this->assertSame(200, $status2);
    }
}
