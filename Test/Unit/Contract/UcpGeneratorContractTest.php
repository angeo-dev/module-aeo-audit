<?php
/**
 * Contract test: angeo/module-ucp (generator) vs UcpProfileChecker (validator).
 *
 * The two modules must agree on what a UCP profile looks like. Each one has
 * its own tests, and both stayed green for six weeks while they disagreed
 * about signing_keys[] vs keys[]. This test builds a profile with the real
 * module-ucp ProfileGenerator and runs the real checker on it, so a change
 * on one side breaks this build.
 *
 * module-ucp is a require-dev dependency. CI also runs on a weekly schedule,
 * so a new module-ucp release is checked even when this repo has no commits.
 * Without module-ucp installed (a plain local checkout) the test is skipped.
 *
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Contract;

use Angeo\AeoAudit\Model\Checker\UcpProfileChecker;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Test\Unit\Model\Checker\CheckerTestHelper;
use PHPUnit\Framework\TestCase;

class UcpGeneratorContractTest extends TestCase
{
    use CheckerTestHelper;

    private const GENERATOR       = 'Angeo\\Ucp\\Model\\ProfileGenerator';
    private const CONFIG          = 'Angeo\\Ucp\\Model\\Config';
    private const REST_PROVIDER   = 'Angeo\\Ucp\\Model\\Service\\RestShoppingBindingProvider';
    private const MCP_PROVIDER    = 'Angeo\\Ucp\\Model\\Service\\McpShoppingBindingProvider';
    private const JWK_FORMATTER   = 'Angeo\\Ucp\\Model\\Keys\\JwkFormatter';
    private const CONTROLLER      = 'Angeo\\Ucp\\Controller\\WellKnown\\Ucp';

    private UcpProfileChecker $checker;

    protected function setUp(): void
    {
        if (!class_exists(self::GENERATOR)) {
            $this->markTestSkipped('angeo/module-ucp is not installed (composer require-dev).');
        }
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new UcpProfileChecker($this->httpCache, $this->urlSampler);
    }

    /** The version the generator publishes must be one the audit knows. */
    public function testGeneratorVersionIsKnownToTheAudit(): void
    {
        $result = $this->audit($this->generate());

        $this->assertTrue(
            $result->getDetails()['version_known'],
            'module-ucp publishes a UCP version this audit does not know yet: '
            . $result->getRecommendation()
        );
    }

    /** A minimal profile: REST transport and one signing key. */
    public function testMinimalGeneratedProfilePasses(): void
    {
        $result = $this->audit($this->generate());

        $this->assertTrue(
            $result->isPassed(),
            sprintf('Got %s: %s | %s', $result->getStatus(), $result->getMessage(), $result->getRecommendation())
        );
        $this->assertSame('keys', $result->getDetails()['keys_field']);
        $this->assertSame(1, $result->getDetails()['signing_keys']);
    }

    /** Every capability, extension and transport the generator can emit. */
    public function testFullGeneratedProfilePasses(): void
    {
        $result = $this->audit($this->generate(full: true));

        $this->assertTrue(
            $result->isPassed(),
            sprintf('Got %s: %s | %s', $result->getStatus(), $result->getMessage(), $result->getRecommendation())
        );
        $this->assertNotEmpty($result->getDetails()['capabilities']);
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(bool $full = false): array
    {
        $config = $this->createStub(self::CONFIG);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getRestEndpoint')->willReturn('https://example.com/rest/V1/ucp');
        $config->method('getMcpEndpoint')->willReturn($full ? 'https://example.com/ucp/mcp' : '');
        $config->method('getPublicKeys')->willReturn([$this->publicJwk()]);
        $config->method('getSupportedVersions')->willReturn([]);
        $config->method('getPaymentHandlers')->willReturn([]);
        foreach ([
            'isCatalogSearchDeclared', 'isCatalogLookupDeclared', 'isCartDeclared',
            'isCheckoutDeclared', 'isOrderDeclared', 'isIdentityLinkingDeclared',
            'isPermalinkDeclared', 'isFulfillmentDeclared', 'isDiscountDeclared',
            'isBuyerConsentDeclared',
        ] as $toggle) {
            if (method_exists(self::CONFIG, $toggle)) {
                $config->method($toggle)->willReturn($full);
            }
        }
        if (method_exists(self::CONFIG, 'getPermalinkEndpoint')) {
            $config->method('getPermalinkEndpoint')->willReturn($full ? 'https://example.com/p' : '');
        }
        if (method_exists(self::CONFIG, 'getIdentityLinkingScopes')) {
            $config->method('getIdentityLinkingScopes')->willReturn($full ? ['profile'] : []);
        }

        // Same provider pool as module-ucp's etc/di.xml.
        $providers = [];
        foreach ([self::REST_PROVIDER, self::MCP_PROVIDER] as $provider) {
            if (class_exists($provider)) {
                $providers[] = new $provider($config);
            }
        }

        $generatorClass = self::GENERATOR;
        return (new $generatorClass($config, $providers))->generate();
    }

    /**
     * A real P-256 public key, formatted by module-ucp's own JwkFormatter.
     *
     * @return array<string, string>
     */
    private function publicJwk(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key, 'OpenSSL could not create an EC key');
        $details = openssl_pkey_get_details($key);

        $formatterClass = self::JWK_FORMATTER;
        return (new $formatterClass())->publicKeyToJwk((string) $details['key']);
    }

    /**
     * Serve the profile the way module-ucp's controller does, then audit it.
     *
     * @param array<string, mixed> $profile
     */
    private function audit(array $profile): CheckResult
    {
        $this->stubUrl(
            'https://example.com/.well-known/ucp',
            200,
            (string) json_encode($profile, JSON_UNESCAPED_SLASHES),
            ['content-type' => 'application/json', 'cache-control' => 'public, max-age=' . $this->controllerMaxAge()]
        );

        return $this->checker->check($this->store);
    }

    /** The controller's Cache-Control max-age, read from module-ucp itself. */
    private function controllerMaxAge(): int
    {
        if (class_exists(self::CONTROLLER)) {
            $class = new \ReflectionClass(self::CONTROLLER);
            if ($class->hasConstant('CACHE_MAX_AGE')) {
                return (int) $class->getConstant('CACHE_MAX_AGE');
            }
        }

        return 300; // module-ucp 2.2 value, if the constant is ever renamed.
    }
}
