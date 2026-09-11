<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\BotHitSourceInterface;
use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\AiCrawlerActivityChecker;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Service\BotRegistry;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class AiCrawlerActivityCheckerTest extends TestCase
{
    use CheckerTestHelper;

    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private Config $config;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLiveSignalWindowDays')->willReturn(30);
    }

    private function makeChecker(array $sources): AiCrawlerActivityChecker
    {
        return new AiCrawlerActivityChecker(
            $this->httpCache,
            $this->urlSampler,
            new BotRegistry(),
            $this->config,
            $sources
        );
    }

    /**
     * @param array<string, array{hits: int, last_seen: string}> $hits
     */
    private function makeSource(bool $available, array $hits, string $code = 'stub'): BotHitSourceInterface
    {
        $source = $this->createMock(BotHitSourceInterface::class);
        $source->method('isAvailable')->willReturn($available);
        $source->method('getHits')->willReturn($hits);
        $source->method('getCode')->willReturn($code);
        $source->method('getLabel')->willReturn('Stub source');
        $source->method('getCoverageNote')->willReturn('stub coverage');
        return $source;
    }

    public function testWarnsWhenNoSourceIsAvailable(): void
    {
        $checker = $this->makeChecker([$this->makeSource(false, [])]);
        $result  = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('No evidence source', $result->getMessage());
    }

    public function testPassesWhenSearchCrawlersObserved(): void
    {
        $checker = $this->makeChecker([
            $this->makeSource(true, [
                'oai_searchbot' => ['hits' => 12, 'last_seen' => '2026-06-28'],
                'gptbot'        => ['hits' => 40, 'last_seen' => '2026-06-30'],
            ]),
        ]);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isPassed(), $result->getMessage());
        $this->assertSame(12, $result->getDetails()['search_bots']['OAI-SearchBot']);
    }

    public function testWarnsWhenOnlyTrainingCrawlersObserved(): void
    {
        $checker = $this->makeChecker([
            $this->makeSource(true, [
                'gptbot'    => ['hits' => 100, 'last_seen' => '2026-06-30'],
                'claudebot' => ['hits' => 55,  'last_seen' => '2026-06-29'],
            ]),
        ]);
        $result = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('TRAINING', $result->getMessage());
    }

    public function testWarnsOnCompleteSilence(): void
    {
        $checker = $this->makeChecker([$this->makeSource(true, [])]);
        $result  = $checker->check($this->store);

        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('No AI crawler activity', $result->getMessage());
    }

    public function testMergesSourcesWithMaxPerBot(): void
    {
        $checker = $this->makeChecker([
            $this->makeSource(true, ['oai_searchbot' => ['hits' => 3, 'last_seen' => '2026-06-20']], 'a'),
            $this->makeSource(true, ['oai_searchbot' => ['hits' => 9, 'last_seen' => '2026-06-28']], 'b'),
        ]);
        $result = $checker->check($this->store);

        // Max, not sum — overlapping sources must not double-count.
        $this->assertSame(9, $result->getDetails()['search_bots']['OAI-SearchBot']);
    }

    public function testNeverFailsCi(): void
    {
        $checker = $this->makeChecker([]);

        $this->assertSame(CheckerInterface::SEVERITY_INFORMATIONAL, $checker->getSeverity());
        $this->assertSame(CheckerInterface::CATEGORY_LIVE_SIGNAL, $checker->getCategory());
        $this->assertSame('ai_crawler_activity', $checker->getCode());
    }
}
