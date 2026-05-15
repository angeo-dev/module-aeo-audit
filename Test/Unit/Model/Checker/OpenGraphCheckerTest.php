<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\OpenGraphChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenGraphCheckerTest extends TestCase
{
    /** @var Curl&MockObject */
    private Curl|MockObject $curl;
    /** @var OpenGraphChecker */
    private OpenGraphChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new OpenGraphChecker($this->curl);
    }

    public function testPassWhenAllRequiredTagsPresent(): void
    {
        $html = $this->buildHtml([
            'og:title'       => 'My Store',
            'og:description' => 'We sell amazing widgets for home and garden use with free shipping.',
            'og:image'       => 'https://example.com/og-image.jpg',
            'og:url'         => 'https://example.com',
            'og:type'        => 'website',
        ]);

        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
        $this->assertEmpty($result->getDetails()['missing']);
    }

    public function testFailWhenMultipleTagsMissing(): void
    {
        $html = '<html><head><title>Store</title></head><body></body></html>';
        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertCount(5, $result->getDetails()['missing']);
    }

    public function testWarnWhenOneTagMissing(): void
    {
        $html = $this->buildHtml([
            'og:title'       => 'My Store',
            'og:description' => 'A good description that is long enough to pass the length check.',
            'og:image'       => 'https://example.com/og.jpg',
            'og:url'         => 'https://example.com',
            // og:type missing
        ]);

        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertContains('og:type', $result->getDetails()['missing']);
    }

    public function testWarnWhenDescriptionTooShort(): void
    {
        $html = $this->buildHtml([
            'og:title'       => 'My Store',
            'og:description' => 'Short',
            'og:image'       => 'https://example.com/og.jpg',
            'og:url'         => 'https://example.com',
            'og:type'        => 'website',
        ]);

        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('short', strtolower($result->getMessage()));
    }

    public function testHandlesAlternateAttributeOrder(): void
    {
        // content= before property= (alternate HTML attribute order)
        $html = '<html><head>'
            . '<meta content="My Store" property="og:title"/>'
            . '<meta content="A very good description that is long enough"'
            . ' property="og:description"/>'
            . '<meta content="https://example.com/og.jpg" property="og:image"/>'
            . '<meta content="https://example.com" property="og:url"/>'
            . '<meta content="website" property="og:type"/>'
            . '</head></html>';

        $this->mockResponse(200, $html);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('open_graph', $this->checker->getCode());
        $this->assertSame(0.7, $this->checker->getWeight());
    }

    private function buildHtml(array $tags): string
    {
        $metaTags = '';
        foreach ($tags as $property => $content) {
            $metaTags .= sprintf('<meta property="%s" content="%s"/>', $property, $content);
        }
        return "<html><head>{$metaTags}</head><body></body></html>";
    }

    private function mockResponse(int $status, string $body): void
    {
        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }
}
