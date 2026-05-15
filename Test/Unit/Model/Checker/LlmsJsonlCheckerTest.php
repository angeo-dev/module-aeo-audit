<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\LlmsJsonlChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LlmsJsonlCheckerTest extends TestCase
{
    /** @var Curl&MockObject */
    private Curl|MockObject $curl;
    /** @var LlmsJsonlChecker */
    private LlmsJsonlChecker $checker;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->checker = new LlmsJsonlChecker($this->curl);
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('llms_jsonl', $this->checker->getCode());
        $this->assertSame(0.75, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
        $this->assertSame('composer require angeo/module-llms-txt', $this->checker->getFixCommand());
    }

    public function testFailWhenFileMissing(): void
    {
        $this->mockResponse(404, '');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testFailWhenInvalidJson(): void
    {
        $this->mockResponse(200, "not valid json\nalso not valid\nstill not\nnope\nnein\n");

        $result = $this->checker->check('https://example.com');

        $this->assertContains(
            $result->getStatus(),
            [CheckerInterface::STATUS_FAIL, CheckerInterface::STATUS_WARN]
        );
    }

    public function testWarnOrPassForValidLinesWithMissingEcomFields(): void
    {
        $body = '';
        for ($i = 0; $i < 6; $i++) {
            $body .= json_encode([
                'title' => 'Product ' . $i,
                'url'   => 'https://example.com/p/' . $i,
            ]) . "\n";
        }
        $this->mockResponse(200, $body);

        $result = $this->checker->check('https://example.com');

        $this->assertNotSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
        $this->curl->method('getHeaders')->willReturn([]);
    }
}
