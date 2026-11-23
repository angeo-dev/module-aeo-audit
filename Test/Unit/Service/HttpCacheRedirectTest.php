<?php
declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Service;

use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\PublicAddress;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;

class HttpCacheRedirectTest extends TestCase
{
    /** @var string[] */
    private array $requested = [];
    /** @var array<int, array{0: int, 1: mixed}> */
    private array $options = [];

    /**
     * @param array<string, array{0: int, 1: string, 2?: string}> $site  url => [status, body, location]
     * @param string[] $publicHosts
     */
    private function client(array $site, array $publicHosts = []): HttpCache
    {
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($site): Curl {
            $curl = $this->createStub(Curl::class);
            $current = '';
            $go = function (string $url) use (&$current): void {
                $current = $url;
                $this->requested[] = $url;
            };
            $curl->method('setOption')->willReturnCallback(function ($name, $value): void {
                $this->options[] = [$name, $value];
            });
            $curl->method('get')->willReturnCallback($go);
            $curl->method('post')->willReturnCallback(function (string $url) use ($go): void {
                $go('POST ' . $url);
            });
            $entry = static function () use (&$current, $site): array {
                return $site[preg_replace('/^POST /', '', $current)] ?? [404, ''];
            };
            $curl->method('getStatus')->willReturnCallback(static fn () => $entry()[0]);
            $curl->method('getBody')->willReturnCallback(static fn () => $entry()[1]);
            $curl->method('getHeaders')->willReturnCallback(static fn () => isset($entry()[2]) ? ['Location' => $entry()[2]] : []);

            return $curl;
        });

        $addresses = $this->createStub(PublicAddress::class);
        $addresses->method('hostIsPublic')->willReturnCallback(static fn (string $h): bool => in_array($h, $publicHosts, true));

        $this->requested = [];
        $this->options = [];

        return new HttpCache($factory, $addresses);
    }

    public function testCurlNeverFollowsRedirectsItself(): void
    {
        $this->client(['https://shop.test/' => [200, 'ok']])->get('https://shop.test/');

        self::assertContains([CURLOPT_FOLLOWLOCATION, false], $this->options);
        self::assertNotContains([CURLOPT_FOLLOWLOCATION, true], $this->options);
    }

    public function testARedirectOnTheSameHostIsFollowedEvenOnAPrivateNetwork(): void
    {
        // shop.test is not "public" here: a store on an internal network.
        $result = $this->client([
            'https://shop.test/robots.txt'    => [301, '', '/en/robots.txt'],
            'https://shop.test/en/robots.txt' => [200, 'User-agent: *'],
        ])->get('https://shop.test/robots.txt');

        self::assertSame(200, $result[0]);
        self::assertSame('User-agent: *', $result[1]);
    }

    public function testARedirectToAnotherPublicHostIsFollowed(): void
    {
        $result = $this->client([
            'https://shop.test/'     => [302, '', 'https://www.shop.example/'],
            'https://www.shop.example/' => [200, 'home'],
        ], ['www.shop.example'])->get('https://shop.test/');

        self::assertSame([200, 'home'], [$result[0], $result[1]]);
    }

    public function testARedirectToAnInternalHostIsNotFollowed(): void
    {
        $client = $this->client([
            'https://shop.test/go'                        => [302, '', 'http://169.254.169.254/latest/meta-data/'],
            'http://169.254.169.254/latest/meta-data/' => [200, 'SECRET'],
        ]);

        self::assertSame([0, '', []], $client->get('https://shop.test/go'));
        self::assertSame(['https://shop.test/go'], $this->requested, 'the internal address was never requested');
    }

    public function testAChainThatLeavesThroughAPublicHostIsStillChecked(): void
    {
        $client = $this->client([
            'https://shop.test/'        => [302, '', 'https://cdn.example/x'],
            'https://cdn.example/x'     => [302, '', 'http://intranet.local/admin'],
            'http://intranet.local/admin' => [200, 'SECRET'],
        ], ['cdn.example']);

        self::assertSame(0, $client->get('https://shop.test/')[0]);
        self::assertNotContains('http://intranet.local/admin', $this->requested);
    }

    public function testNoMoreThanThreeRedirects(): void
    {
        $client = $this->client([
            'https://shop.test/a' => [302, '', '/b'],
            'https://shop.test/b' => [302, '', '/c'],
            'https://shop.test/c' => [302, '', '/d'],
            'https://shop.test/d' => [302, '', '/e'],
            'https://shop.test/e' => [200, 'never'],
        ]);

        self::assertSame(0, $client->get('https://shop.test/a')[0]);
        self::assertCount(4, $this->requested);
    }

    public function testARedirectAfterPostBecomesAGetExceptFor307(): void
    {
        $this->client([
            'https://api.example/q'  => [303, '', '/result'],
            'https://api.example/result' => [200, '{}'],
        ])->post('https://api.example/q', '{"a":1}');
        self::assertSame(['POST https://api.example/q', 'https://api.example/result'], $this->requested);

        $this->client([
            'https://api.example/q'  => [307, '', '/q2'],
            'https://api.example/q2' => [200, '{}'],
        ])->post('https://api.example/q', '{"a":1}');
        self::assertSame(['POST https://api.example/q', 'POST https://api.example/q2'], $this->requested);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function locations(): array
    {
        return [
            'absolute'           => ['https://shop.test/a/b', 'https://other.test/x', 'https://other.test/x'],
            'scheme-relative'    => ['https://shop.test/a/b', '//other.test/x', 'https://other.test/x'],
            'absolute path'      => ['https://shop.test:8443/a/b', '/x?y=1', 'https://shop.test:8443/x?y=1'],
            'relative path'      => ['https://shop.test/a/b', 'c', 'https://shop.test/a/c'],
            'file scheme'        => ['https://shop.test/', 'file:///etc/passwd', null],
            'gopher scheme'      => ['https://shop.test/', 'gopher://10.0.0.1/', null],
            'javascript'         => ['https://shop.test/', 'javascript:alert(1)', null],
            'credentials'        => ['https://shop.test/', 'https://user:pw@other.test/', null],
            'empty'              => ['https://shop.test/', '  ', null],
        ];
    }

    /**
     * @dataProvider locations
     */
    public function testLocationHeadersAreResolvedToWebUrlsOnly(string $base, string $location, ?string $expected): void
    {
        self::assertSame($expected, HttpCache::resolveRedirect($base, $location));
    }

    public function testThePublicAddressCheck(): void
    {
        $check = new PublicAddress();

        self::assertTrue($check->hostIsPublic('8.8.8.8'));
        self::assertFalse($check->hostIsPublic('10.0.0.5'));
        self::assertFalse($check->hostIsPublic('127.0.0.1'));
        self::assertFalse($check->hostIsPublic('169.254.169.254'));
        self::assertFalse($check->hostIsPublic('100.64.0.1'));
        self::assertFalse($check->hostIsPublic('[::1]'));
        self::assertFalse($check->hostIsPublic('::ffff:10.0.0.1'));
        self::assertFalse($check->hostIsPublic(''));
    }
}
