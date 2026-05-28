<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\CoreWebVitalsChecker;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class CoreWebVitalsCheckerTest extends TestCase
{
    use CheckerTestHelper;

    public function testWarnsWhenApiKeyNotConfigured(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null);

        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('CrUX API key not configured', $result->getMessage());
        $this->assertSame(CheckerInterface::CATEGORY_EXTERNAL_API, $checker->getCategory());
    }

    public function testMetadata(): void
    {
        $this->bootCheckerMocks();
        $config = $this->createMock(ScopeConfigInterface::class);
        $checker = new CoreWebVitalsChecker($this->httpCache, $this->urlSampler, $config);

        $this->assertSame('core_web_vitals', $checker->getCode());
        $this->assertSame(0.5, $checker->getWeight());
        // Severity for weight 0.5 should be info (not critical or important)
        $this->assertSame(CheckerInterface::SEVERITY_INFORMATIONAL, $checker->getSeverity());
    }
}
