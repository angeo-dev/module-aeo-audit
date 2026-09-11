<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\BotHitSource;

use Angeo\AeoAudit\Model\BotHitSource\AccessLogFileSource;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Service\BotRegistry;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

class AccessLogFileSourceTest extends TestCase
{
    private AccessLogFileSource $source;

    protected function setUp(): void
    {
        $this->source = new AccessLogFileSource(
            $this->createMock(Config::class),
            new BotRegistry(),
            $this->createMock(FileDriver::class)
        );
    }

    public function testAggregatesCombinedFormatByBot(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('d/M/Y');
        $log = implode("\n", [
            $this->combinedLine($today, 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'),
            $this->combinedLine($today, 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'),
            $this->combinedLine($today, 'Mozilla/5.0 (compatible; PerplexityBot/1.0)'),
            $this->combinedLine($today, 'Mozilla/5.0 (Windows NT 10.0) Chrome/126.0'), // human — ignored
        ]);

        $result = $this->source->aggregate($log, 30);

        $this->assertSame(2, $result['gptbot']['hits']);
        $this->assertSame(1, $result['perplexitybot']['hits']);
        $this->assertArrayNotHasKey('claudebot', $result);
        $this->assertCount(2, $result);
    }

    public function testOldEntriesOutsideWindowAreExcluded(): void
    {
        $old   = (new \DateTimeImmutable('-90 days'))->format('d/M/Y');
        $fresh = (new \DateTimeImmutable('today'))->format('d/M/Y');

        $log = $this->combinedLine($old, 'GPTBot/1.2') . "\n"
             . $this->combinedLine($fresh, 'GPTBot/1.2') . "\n";

        $result = $this->source->aggregate($log, 30);

        $this->assertSame(1, $result['gptbot']['hits']);
        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $result['gptbot']['last_seen']);
    }

    public function testParsesJsonLinesFormat(): void
    {
        $today = (new \DateTimeImmutable('today'))->format(\DateTimeInterface::ATOM);
        $log = json_encode([
                'time_iso8601' => $today,
                'remote_addr' => '203.0.113.9',
                'request' => 'GET / HTTP/1.1',
                'user_agent' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            ]) . "\n"
            . json_encode(['time_iso8601' => $today, 'user_agent' => 'Chrome/126.0 human browser']) . "\n";

        $result = $this->source->aggregate($log, 30);

        $this->assertSame(1, $result['claudebot']['hits']);
        $this->assertCount(1, $result);
    }

    public function testGarbageLinesAreSkippedSilently(): void
    {
        $log = "\x00\x00binary garbage\n{not valid json\nplain text without quotes\n";

        $this->assertSame([], $this->source->aggregate($log, 30));
    }

    public function testNoPersonalDataInAggregates(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('d/M/Y');
        $log   = $this->combinedLine($today, 'GPTBot/1.2');

        $result = $this->source->aggregate($log, 30);

        // The aggregate must contain counters only — no IPs, URLs or UA strings.
        $this->assertSame(['hits', 'last_seen'], array_keys($result['gptbot']));
        $flat = json_encode($result);
        $this->assertStringNotContainsString('203.0.113.7', (string) $flat);
        $this->assertStringNotContainsString('/some/product', (string) $flat);
    }

    private function combinedLine(string $date, string $ua): string
    {
        return sprintf(
            '203.0.113.7 - - [%s:03:14:15 +0000] "GET /some/product HTTP/1.1" 200 5123 "-" "%s"',
            $date,
            $ua
        );
    }
}
