<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\LlmsTxtChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LlmsTxtCheckerTest extends TestCase
{
    /** @var Curl&MockObject */
    private Curl|MockObject $curl;
    /** @var LlmsTxtChecker */
    private LlmsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new LlmsTxtChecker($this->curl);
    }

    public function testPassWhenWellFormed(): void
    {
        $content = implode("\n", [
            '# My Store',
            '',
            '## Products',
            '- [Widget A](https://example.com/widget-a): Our best seller',
            '- [Widget B](https://example.com/widget-b): New arrival',
            '',
            '## Categories',
            '- [Tools](https://example.com/tools): Hand and power tools',
            '',
            '## About',
            '- [FAQ](https://example.com/faq): Common questions',
        ]);

        $this->mockSequence([
            [200, $content],  // llms.txt
            [200, ''],         // llms-full.txt
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
        $this->assertStringContainsString('section', $result->getMessage());
        $this->assertStringContainsString('link', $result->getMessage());
    }

    public function testFailWhenNotFound(): void
    {
        $this->mockSequence([[404, '']]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertNotEmpty($result->getRecommendation());
        $this->assertStringContainsString('angeo:llms:generate', $result->getRecommendation());
    }

    public function testWarnWhenMissingH1(): void
    {
        $content = "## Products\n- [A](https://example.com/a): item\n";

        $this->mockSequence([
            [200, $content],
            [404, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('H1', $result->getRecommendation());
    }

    public function testWarnWhenNoLinks(): void
    {
        $content = "# My Store\n\n## Products\nWe sell things.\n";

        $this->mockSequence([
            [200, $content],
            [404, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('link', strtolower($result->getRecommendation()));
    }

    public function testWarnWhenContentTooShort(): void
    {
        $this->mockSequence([
            [200, '# Store'],
            [404, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
    }

    public function testDetailsContainSectionAndLinkCounts(): void
    {
        $content = implode("\n", [
            '# Store',
            '## Products',
            '- [A](https://example.com/a): item',
            '- [B](https://example.com/b): item',
            '## About',
            '- [C](https://example.com/c): item',
        ]);

        $this->mockSequence([
            [200, $content],
            [404, ''],
        ]);

        $result  = $this->checker->check('https://example.com');
        $details = $result->getDetails();

        $this->assertSame(2, $details['sections']);
        $this->assertSame(3, $details['links']);
        $this->assertFalse($details['llms_full_txt']);
    }

    public function testFullTxtBonusReportedInDetails(): void
    {
        $content = "# Store\n## Products\n- [A](https://example.com/a): item\n";

        $this->mockSequence([
            [200, $content],
            [200, 'full content here'],
        ]);

        $result  = $this->checker->check('https://example.com');
        $details = $result->getDetails();

        $this->assertTrue($details['llms_full_txt']);
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('llms_txt', $this->checker->getCode());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
    }

    private function mockSequence(array $responses): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(
            ...array_column($responses, 0)
        );
        $this->curl->method('getBody')->willReturnOnConsecutiveCalls(
            ...array_column($responses, 1)
        );
    }
}
