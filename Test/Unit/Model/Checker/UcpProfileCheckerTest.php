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
        $this->stubUrl('https://example.com/.well-known/ucp.json', 404, '');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    /**
     * When signing keys ARE configured in env.php but the endpoint still 404s,
     * the failure must say "configured but not served" and must NOT advise
     * regenerating keys (the keys are fine; the endpoint isn't reachable).
     */
    public function testKeysConfiguredButProfileNotServedGivesTargetedDiagnosis(): void
    {
        $deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfig->method('get')
            ->with('ucp/signing_keys')
            ->willReturn(['angeo-ucp-2026' => 'private-key-material']);

        $checker = new UcpProfileChecker($this->httpCache, $this->urlSampler, $deploymentConfig);

        $this->stubUrl('https://example.com/.well-known/ucp', 404, '');
        $this->stubUrl('https://example.com/.well-known/ucp.json', 404, '');

        $result = $checker->check($this->store);
        $text = $result->getMessage() . ' | ' . $result->getRecommendation();

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('configured but the profile is not served', $result->getMessage());
        $this->assertStringContainsString('/.well-known/', $text);
        $this->assertStringNotContainsString('angeo:ucp:keys:generate', $text);
        $this->assertTrue($result->getDetails()['signing_keys_configured']);
    }

    public function testNoKeysConfiguredAnd404AdvisesInstall(): void
    {
        $deploymentConfig = $this->createMock(\Magento\Framework\App\DeploymentConfig::class);
        $deploymentConfig->method('get')->with('ucp/signing_keys')->willReturn(null);

        $checker = new UcpProfileChecker($this->httpCache, $this->urlSampler, $deploymentConfig);

        $this->stubUrl('https://example.com/.well-known/ucp', 404, '');
        $this->stubUrl('https://example.com/.well-known/ucp.json', 404, '');

        $result = $checker->check($this->store);

        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('UCP profile not found', $result->getMessage());
        $this->assertStringContainsString('angeo:ucp:keys:generate', $result->getRecommendation());
        $this->assertFalse($result->getDetails()['signing_keys_configured']);
    }

    /** The .json alias is accepted when the canonical path is absent. */
    public function testJsonAliasPathIsProbed(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-08-25',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities' => ['catalog' => true],
            ],
            'keys' => [[
                'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'k1', 'x' => 'x', 'y' => 'y',
            ]],
        ];
        $this->stubUrl('https://example.com/.well-known/ucp', 404, '');
        $this->stubUrl(
            'https://example.com/.well-known/ucp.json',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
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
                'version'  => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-08-25',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities' => [],
            ],
            'keys' => [[
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
                'version'  => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-08-25',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities' => ['catalog' => true],
            ],
            'keys' => [[
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
                'version'  => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-08-25',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
            ],
            'keys' => [[
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
                'version'  => '2026-08-25',
                'services' => [],
            ],
            'keys' => [],
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

    /** A profile on the previous revision still validates, but is told it is behind. */
    public function testWarnsOnSupersededRevisionWithLegacyKeys(): void
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
                'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'k1', 'x' => 'x', 'y' => 'y',
            ]],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('superseded', $result->getRecommendation());
        $this->assertSame('signing_keys', $result->getDetails()['keys_field']);
        $this->assertSame(1, $result->getDetails()['signing_keys']);
    }

    /** 2026-08-25 removed signing_keys[]; a current profile that still uses it is told to move. */
    public function testWarnsWhenCurrentRevisionStillUsesSigningKeys(): void
    {
        $profile = [
            'ucp' => [
                'version'  => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => '2026-08-25',
                        'spec'      => 'https://spec',
                        'transport' => 'rest+jsonld',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
            ],
            'signing_keys' => [[
                'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'k1', 'x' => 'x', 'y' => 'y',
            ]],
        ];
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('keys[]', $result->getRecommendation());
    }


    // ── Unknown protocol version (4.3.1) ────────────────────────────────

    /**
     * A newer revision may rename fields. A "missing" finding on an unknown
     * version must be a warning that says so, never a failure.
     */
    public function testUnknownVersionTurnsMissingServiceIntoWarning(): void
    {
        $profile = $this->currentProfile('2027-01-01');
        unset($profile['ucp']['services']);
        $result = $this->serve($profile);

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('dev.ucp.shopping', $result->getRecommendation());
        $this->assertStringContainsString(
            'may be named differently in UCP 2027-01-01',
            $result->getRecommendation()
        );
        $this->assertStringContainsString('may be newer', $result->getRecommendation());
        $this->assertStringNotContainsString('valid', $result->getMessage());
        $this->assertFalse($result->getDetails()['version_known']);
    }

    public function testUnknownVersionTurnsMissingServiceFieldIntoWarning(): void
    {
        $profile = $this->currentProfile('2027-01-01');
        unset($profile['ucp']['services']['dev.ucp.shopping'][0]['schema']);
        $result = $this->serve($profile);

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('[0].schema missing (not confirmed', $result->getRecommendation());
    }

    public function testUnknownVersionMarksMissingKeysAsNotConfirmed(): void
    {
        $profile = $this->currentProfile('2027-01-01');
        unset($profile['keys']);
        $result = $this->serve($profile);

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('No signing keys declared', $result->getRecommendation());
        $this->assertStringContainsString('may be named differently', $result->getRecommendation());
    }

    /** Leaked private key material is a failure on any version. */
    public function testUnknownVersionStillFailsOnLeakedPrivateKey(): void
    {
        $profile = $this->currentProfile('2027-01-01');
        $profile['keys'][0]['d'] = 'private';
        $result = $this->serve($profile);

        $this->assertTrue($result->isFailed(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('SECURITY', $result->getMessage());
    }

    /** A plain-HTTP endpoint is wrong on any version. */
    public function testUnknownVersionStillFailsOnHttpEndpoint(): void
    {
        $profile = $this->currentProfile('2027-01-01');
        $profile['ucp']['services']['dev.ucp.shopping'][0]['endpoint'] = 'http://example.com/api/ucp';
        $result = $this->serve($profile);

        $this->assertTrue($result->isFailed(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('not HTTPS', $result->getMessage());
    }

    /** On a known version a missing field is still a failure. */
    public function testKnownVersionStillFailsOnMissingService(): void
    {
        $profile = $this->currentProfile('2026-08-25');
        unset($profile['ucp']['services']);
        $result = $this->serve($profile);

        $this->assertTrue($result->isFailed(), 'Got ' . $result->getStatus());
        $this->assertStringNotContainsString('not confirmed', $result->getRecommendation());
        $this->assertTrue($result->getDetails()['version_known']);
    }

    public function testOlderUnknownVersionIsNotCalledNewer(): void
    {
        $result = $this->serve($this->currentProfile('2025-10-01'));

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('older than every revision', $result->getRecommendation());
        $this->assertStringNotContainsString('may be newer', $result->getRecommendation());
    }

    public function testUnpublishedVersionBetweenKnownOnesIsNamedAsSuch(): void
    {
        $result = $this->serve($this->currentProfile('2026-06-01'));

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('not a published revision', $result->getRecommendation());
    }

    // ── Malformed capabilities (4.3.1) ──────────────────────────────────

    /** A non-object capabilities value used to throw a TypeError. */
    public function testNonArrayCapabilitiesWarnsInsteadOfCrashing(): void
    {
        $profile = $this->currentProfile('2026-08-25');
        $profile['ucp']['capabilities'] = 'catalog';
        $result = $this->serve($profile);

        $this->assertTrue($result->isWarning(), 'Got ' . $result->getStatus());
        $this->assertStringContainsString('ucp.capabilities must be an object', $result->getRecommendation());
        $this->assertSame([], $result->getDetails()['capabilities']);
    }

    public function testCurrentProfilePasses(): void
    {
        $result = $this->serve($this->currentProfile('2026-08-25'));

        $this->assertTrue(
            $result->isPassed(),
            'Got ' . $result->getStatus() . ': ' . $result->getRecommendation()
        );
        $this->assertSame('keys', $result->getDetails()['keys_field']);
    }

    /**
     * A complete, valid profile on the given version, in the 2026-08-25 shape.
     *
     * @return array<string, mixed>
     */
    private function currentProfile(string $version): array
    {
        return [
            'ucp' => [
                'version'  => $version,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => $version,
                        'spec'      => 'https://spec',
                        'transport' => 'rest',
                        'endpoint'  => 'https://example.com/api/ucp',
                        'schema'    => 'https://schema',
                    ]],
                ],
                'capabilities'     => ['dev.ucp.shopping.checkout' => [['version' => $version]]],
                'payment_handlers' => [],
            ],
            'keys' => [[
                'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'kid' => 'k1', 'x' => 'x', 'y' => 'y',
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function serve(array $profile): \Angeo\AeoAudit\Model\Report\CheckResult
    {
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            (string) json_encode($profile),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=300']
        );

        return $this->checker->check($this->store);
    }
}
