<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\SitemapXmlChecker;
use Angeo\AeoAudit\Model\Config;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use PHPUnit\Framework\TestCase;

class SitemapXmlCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private SitemapXmlChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn(100);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $config = $this->createMock(Config::class);
        $config->method('getSitemapSlugMode')->willReturn(Config::SLUG_MODE_SCORE);
        $config->method('getSitemapSlugThreshold')->willReturn(1);

        // v3.1.0 shipped this test against a pre-Config 3-argument constructor
        // and it never ran (no CI); aligned with the real signature in 4.0.0.
        $this->checker = new SitemapXmlChecker(
            $this->httpCache,
            $this->urlSampler,
            $factory,
            $this->createMock(CategoryCollectionFactory::class),
            $this->createMock(CmsPageCollectionFactory::class),
            $config
        );
    }

    public function testFailWhenNothingFound(): void
    {
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/pub/sitemap.xml'] as $p) {
            $this->stubUrl('https://example.com' . $p, 404, '');
        }
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testInvalidXmlIsFailed(): void
    {
        $this->stubUrl('https://example.com/sitemap.xml', 200, 'not xml');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testSitemapIndexIsPass(): void
    {
        $body = '<?xml version="1.0"?><sitemapindex>'
            . '<sitemap><loc>https://example.com/sm-1.xml</loc></sitemap>'
            . '<sitemap><loc>https://example.com/sm-2.xml</loc></sitemap>'
            . '</sitemapindex>';
        $this->stubUrl('https://example.com/sitemap.xml', 200, $body);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
    }

    public function testValidSitemapPasses(): void
    {
        $urls = [];
        // 100 URLs to match our mocked catalog size
        for ($i = 1; $i <= 100; $i++) {
            $urls[] = "<url><loc>https://example.com/product-$i</loc></url>";
        }
        $body = '<?xml version="1.0"?><urlset>' . implode('', $urls) . '</urlset>';
        $this->stubUrl('https://example.com/sitemap.xml', 200, $body);
        $this->stubUrl('https://example.com/sitemap.xml.gz', 200, '');
        $this->stubUrl('https://example.com/robots.txt', 200, "Sitemap: https://example.com/sitemap.xml\n");

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testDisproportionIsReportedAsContextNotStatus(): void
    {
        // v3 warned when sitemap URL count diverged from catalog size; that
        // punished legitimate multi-store / filtered sitemaps, so v3.1 turned
        // the ratio into pure INFO context. The test previously asserted the
        // removed WARN behaviour and never ran (no CI); aligned in 4.0.0.
        $urls = '';
        for ($i = 1; $i <= 10; $i++) {
            $urls .= "<url><loc>https://example.com/p-$i</loc></url>";
        }
        $body = '<?xml version="1.0"?><urlset>' . $urls . '</urlset>';
        $this->stubUrl('https://example.com/sitemap.xml', 200, $body);
        $this->stubUrl('https://example.com/robots.txt', 200, 'Sitemap: https://example.com/sitemap.xml');

        $result  = $this->checker->check($this->store);
        $details = $result->getDetails();

        // Ratio (10 URLs / 100 indexable products) is surfaced in details…
        $this->assertSame(0.1, $details['coverage_ratio']);
        // …but never demotes the status by itself.
        $this->assertFalse(
            $result->isFailed(),
            'Coverage disproportion must not fail the check: ' . $result->getMessage()
        );
    }
}
