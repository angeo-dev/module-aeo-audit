<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\UcpProfileChecker;
use PHPUnit\Framework\TestCase;

class UcpProfileCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private UcpProfileChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new UcpProfileChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailWhenBaseUrlIsHttp(): void
    {
        $sampler = $this->createMock(\Angeo\AeoAudit\Service\StoreUrlSampler::class);
        $sampler->method('getBaseUrl')->willReturn('http://example.com');
        $checker = new UcpProfileChecker($this->httpCache, $sampler);
        $result = $checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('HTTPS', $result->getMessage());
    }

    public function testFailWhen404(): void
    {
        $this->stubUrl('https://example.com/.well-known/ucp', 404, '');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testFailWhenNotJson(): void
    {
        $this->stubUrl('https://example.com/.well-known/ucp', 200, '<html>not json</html>',
            ['content-type' => 'text/html']);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('content-type', $result->getMessage());
    }

    public function testFailWhenJsonInvalid(): void
    {
        $this->stubUrl('https://example.com/.well-known/ucp', 200, '{ broken',
            ['content-type' => 'application/json']);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testSecurityFailureOnLeakedPrivateKeyField(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-04-08',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-04-08',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities' => [],
            ],
            'signing_keys' => [[
                'kty' => 'EC',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'kid' => 'k1',
                'x'   => 'xxx',
                'y'   => 'yyy',
                'd'   => 'LEAKED-PRIVATE-KEY', // SECURITY!
            ]],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('SECURITY', $result->getMessage() . $result->getRecommendation());
        $this->assertStringContainsString('private', strtolower($result->getMessage()
            . $result->getRecommendation()));
    }

    public function testPassesWithCleanProfile(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-04-08',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-04-08',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities' => ['catalog' => true],
            ],
            'signing_keys' => [[
                'kty' => 'EC',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'kid' => 'k1',
                'x'   => 'xxx',
                'y'   => 'yyy',
            ]],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': '
            . $result->getMessage() . ' | ' . $result->getRecommendation());
    }

    public function testWarnsOnLowCacheMaxAge(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-04-08',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-04-08',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
            ],
            'signing_keys' => [[
                'kty' => 'EC',
                'crv' => 'P-256',
                'alg' => 'ES256',
                'kid' => 'k1',
                'x'   => 'xxx',
                'y'   => 'yyy',
            ]],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=10']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('max-age', $result->getRecommendation());
    }

    public function testFailWhenShoppingServiceMissing(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-04-08',
                'services' => [],
            ],
            'signing_keys' => [],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('dev.ucp.shopping', $result->getMessage()
            . $result->getRecommendation());
    }
}
