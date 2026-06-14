<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\CoreWebVitalsChecker;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class CoreWebVitalsCheckerTest extends TestCase
{
    use CheckerTestHelper;

    public function testWarnsWhenApiKeyNotConfigured(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config, $encryptor);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('CrUX API key not configured', $result->getMessage());
        $this->assertSame(CheckerInterface::CATEGORY_EXTERNAL_API, $checker->getCategory());
    }

    /**
     * Regression test for the 3.1.0 fix: the stored value is ciphertext
     * (Backend\Encrypted) and MUST be decrypted before use; the decrypted
     * key MUST be sent in the X-Goog-Api-Key header — never in the URL.
     */
    public function testDecryptsKeyAndSendsItInHeaderNotUrl(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('0:3:ciphertext==');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())
            ->method('decrypt')
            ->with('0:3:ciphertext==')
            ->willReturn('plain-api-key');

        $capturedUrl = null;
        $capturedHeaders = null;
        $this->httpCache->method('post')->willReturnCallback(
            function (string $url, string $payload, array $headers) use (&$capturedUrl, &$capturedHeaders) {
                $capturedUrl = $url;
                $capturedHeaders = $headers;
                return [200, json_encode([
                    'record' => [
                        'metrics' => [
                            'largest_contentful_paint'  => ['percentiles' => ['p75' => 2000]],
                            'interaction_to_next_paint' => ['percentiles' => ['p75' => 150]],
                            'cumulative_layout_shift'   => ['percentiles' => ['p75' => 0.05]],
                        ],
                    ],
                ]), []];
            }
        );

        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config, $encryptor);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isPassed());
        $this->assertStringNotContainsString('plain-api-key', (string) $capturedUrl);
        $this->assertStringNotContainsString('key=', (string) $capturedUrl);
        $this->assertSame('plain-api-key', $capturedHeaders['X-Goog-Api-Key'] ?? null);
    }

    public function testWarnsWhenCruxCallFails(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('0:3:ciphertext==');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('plain-api-key');

        $this->httpCache->method('post')->willReturn([0, '', []]);

        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config, $encryptor);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('CrUX API call failed', $result->getMessage());
    }

    public function testTreatsUndecryptableKeyAsUnconfigured(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('corrupted');
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willThrowException(new \RuntimeException('bad crypt key'));

        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config, $encryptor);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('CrUX API key not configured', $result->getMessage());
    }

    public function testMetadata(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config, $encryptor);

        $this->assertSame('core_web_vitals', $checker->getCode());
        $this->assertSame(0.5, $checker->getWeight());
        // Severity for weight 0.5 should be info (not critical or important)
        $this->assertSame(CheckerInterface::SEVERITY_INFORMATIONAL, $checker->getSeverity());
    }
}
