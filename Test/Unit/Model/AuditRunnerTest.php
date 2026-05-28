<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditRunnerTest extends TestCase
{
    public function testRunsAllCheckersAndReturnsOneReportPerStore(): void
    {
        $store1 = $this->mockStore('default', 'https://default.example/');
        $store2 = $this->mockStore('alt', 'https://alt.example/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store1, $store2]);

        $checker1 = $this->fakeChecker('a', 'A', 1.0, CheckerInterface::CATEGORY_TECHNICAL);
        $checker2 = $this->fakeChecker('b', 'B', 0.5, CheckerInterface::CATEGORY_TECHNICAL);

        $runner = new AuditRunner(
            $storeManager,
            $this->createMock(HttpCache::class),
            $this->createMock(StoreUrlSampler::class),
            $this->createMock(LoggerInterface::class),
            [$checker1, $checker2],
        );

        $reports = $runner->runAll();
        $this->assertCount(2, $reports);
        $this->assertCount(2, $reports[0]->getResults());
        $this->assertCount(2, $reports[1]->getResults());
    }

    public function testCategoryFilteringExcludesNonMatching(): void
    {
        $store = $this->mockStore('default', 'https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        $tech = $this->fakeChecker('a', 'tech', 1.0, CheckerInterface::CATEGORY_TECHNICAL);
        $live = $this->fakeChecker('b', 'live', 1.0, CheckerInterface::CATEGORY_LIVE_SIGNAL);

        $runner = new AuditRunner(
            $storeManager,
            $this->createMock(HttpCache::class),
            $this->createMock(StoreUrlSampler::class),
            $this->createMock(LoggerInterface::class),
            [$tech, $live],
        );

        $reports = $runner->runAll(null, [CheckerInterface::CATEGORY_TECHNICAL]);
        $this->assertCount(1, $reports[0]->getResults());
        $this->assertSame('a', $reports[0]->getResults()[0]->getCheckCode());
    }

    public function testCheckerExceptionDoesNotHaltRun(): void
    {
        $store = $this->mockStore('default', 'https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);

        $broken = $this->createMock(CheckerInterface::class);
        $broken->method('getName')->willReturn('Broken');
        $broken->method('getCode')->willReturn('broken');
        $broken->method('getWeight')->willReturn(1.0);
        $broken->method('getCategory')->willReturn(CheckerInterface::CATEGORY_TECHNICAL);
        $broken->method('getSeverity')->willReturn(CheckerInterface::SEVERITY_CRITICAL);
        $broken->method('getFixCommand')->willReturn('');
        $broken->method('check')->willThrowException(new \RuntimeException('boom'));

        $working = $this->fakeChecker('ok', 'Ok', 1.0, CheckerInterface::CATEGORY_TECHNICAL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $runner = new AuditRunner(
            $storeManager,
            $this->createMock(HttpCache::class),
            $this->createMock(StoreUrlSampler::class),
            $logger,
            [$broken, $working],
        );

        $reports = $runner->runAll();
        $this->assertCount(2, $reports[0]->getResults());
        $this->assertTrue($reports[0]->getResults()[0]->isFailed());
        $this->assertTrue($reports[0]->getResults()[1]->isPassed());
    }

    public function testHttpCacheAndUrlSamplerAreResetBetweenStores(): void
    {
        $store1 = $this->mockStore('a', 'https://a.example/');
        $store2 = $this->mockStore('b', 'https://b.example/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store1, $store2]);

        $httpCache = $this->createMock(HttpCache::class);
        $httpCache->expects($this->exactly(2))->method('reset');

        $sampler = $this->createMock(StoreUrlSampler::class);
        $sampler->expects($this->exactly(2))->method('reset');
        $sampler->method('getBaseUrl')->willReturnOnConsecutiveCalls('https://a.example', 'https://b.example');

        $runner = new AuditRunner(
            $storeManager,
            $httpCache,
            $sampler,
            $this->createMock(LoggerInterface::class),
            [$this->fakeChecker('x', 'X', 1.0)],
        );

        $runner->runAll();
    }

    private function mockStore(string $code, string $baseUrl): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn($code);
        $store->method('getBaseUrl')->willReturn($baseUrl);
        return $store;
    }

    private function fakeChecker(
        string $code,
        string $name,
        float  $weight,
        string $category = CheckerInterface::CATEGORY_TECHNICAL,
    ): CheckerInterface {
        $checker = $this->createMock(CheckerInterface::class);
        $checker->method('getName')->willReturn($name);
        $checker->method('getCode')->willReturn($code);
        $checker->method('getWeight')->willReturn($weight);
        $checker->method('getCategory')->willReturn($category);
        $checker->method('getSeverity')->willReturn(CheckerInterface::SEVERITY_CRITICAL);
        $checker->method('getFixCommand')->willReturn('');
        $checker->method('check')->willReturn(
            CheckResult::pass($name, 'ok', [], $code, $weight)
        );
        return $checker;
    }
}
