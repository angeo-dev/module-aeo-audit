<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\SitemapXmlChecker;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SitemapXmlCheckerTest extends TestCase
{
    private Curl|MockObject $curl;
    private SitemapXmlChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new SitemapXmlChecker($this->curl);
    }

    public function testPassWhenSitemapExistsAndInRobots(): void
    {
        $sitemap = $this->buildSitemap(100, date('Y-m-d'));
        $robots  = "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml\n";

        $this->mockSequence([
            [200, $sitemap], // sitemap.xml
            [200, $robots],  // robots.txt
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
        $this->assertSame(100, $result->getDetails()['url_count']);
    }

    public function testWarnWhenNotReferencedInRobots(): void
    {
        $sitemap = $this->buildSitemap(50, date('Y-m-d'));
        $robots  = "User-agent: *\nAllow: /\n";

        $this->mockSequence([
            [200, $sitemap],
            [200, $robots],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('robots.txt', $result->getMessage());
    }

    public function testFailWhenNotFound(): void
    {
        // All three candidates return 404
        $this->mockSequence([
            [404, ''],
            [404, ''],
            [404, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
    }

    public function testFailWhenInvalidXml(): void
    {
        $this->mockSequence([
            [200, 'this is not xml at all <<<'],
            [200, ''],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        $this->assertStringContainsString('valid XML', $result->getMessage());
    }

    public function testWarnWhenTooFewUrls(): void
    {
        $sitemap = $this->buildSitemap(2, date('Y-m-d'));
        $robots  = "Sitemap: https://example.com/sitemap.xml\n";

        $this->mockSequence([
            [200, $sitemap],
            [200, $robots],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('2 URLs', $result->getMessage());
    }

    public function testWarnWhenSitemapIsStale(): void
    {
        $staleDate = date('Y-m-d', strtotime('-100 days'));
        $sitemap   = $this->buildSitemap(500, $staleDate);
        $robots    = "Sitemap: https://example.com/sitemap.xml\n";

        $this->mockSequence([
            [200, $sitemap],
            [200, $robots],
        ]);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        $this->assertStringContainsString('stale', $result->getMessage());
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('sitemap', $this->checker->getCode());
        $this->assertSame(0.8, $this->checker->getWeight());
    }

    private function buildSitemap(int $urlCount, string $lastmod): string
    {
        $urls = '';
        for ($i = 0; $i < $urlCount; $i++) {
            $urls .= "<url><loc>https://example.com/page-{$i}</loc><lastmod>{$lastmod}</lastmod></url>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $urls
            . "</urlset>";
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
