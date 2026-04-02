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
    private Curl|MockObject $curlMock;
    private LlmsTxtChecker $checker;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->checker  = new LlmsTxtChecker($this->curlMock);
    }

    public function testPassWhenLlmsTxtWellFormed(): void
    {
        $content = implode("\n", [
            '# My Store',
            '## About',
            'We sell great products.',
            '## Products',
            '- Product A: https://example.com/product-a',
            '## Categories',
            '- Category: https://example.com/cat',
            '## CMS',
            '- FAQ: https://example.com/faq',
        ]);

        $this->mockFetchSequence([
            [200, $content],
            [200, ''],  // llms-full.txt check
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testFailWhenLlmsTxtMissing(): void
    {
        $this->mockFetchSequence([[404, '']]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testWarnWhenContentTooShort(): void
    {
        $this->mockFetchSequence([
            [200, '# Store'],
            [404, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
    }

    public function testGetName(): void
    {
        $this->assertNotEmpty($this->checker->getName());
    }

    private function mockFetchSequence(array $responses): void
    {
        $this->curlMock->method('setTimeout')->willReturnSelf();
        $this->curlMock->method('setOption')->willReturnSelf();
        $this->curlMock->method('addHeader')->willReturnSelf();
        $this->curlMock->method('get')->willReturnSelf();

        $statuses = array_column($responses, 0);
        $bodies   = array_column($responses, 1);

        $this->curlMock->method('getStatus')->willReturnOnConsecutiveCalls(...$statuses);
        $this->curlMock->method('getBody')->willReturnOnConsecutiveCalls(...$bodies);
    }
}
