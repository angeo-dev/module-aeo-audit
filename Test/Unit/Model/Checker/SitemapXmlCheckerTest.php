<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\SitemapXmlChecker;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Angeo\AeoAudit\Model\Config;
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

        $categoryFactory = $this->createMock(CategoryCollectionFactory::class);
        $cmsFactory = $this->createMock(CmsPageCollectionFactory::class);
        $config = $this->createMock(Config::class);
        $config->method('isCheckerEnabled')->willReturn(true);

        $this->checker = new SitemapXmlChecker(
            $this->httpCache,
            $this->urlSampler,
            $factory,
            $categoryFactory,
            $cmsFactory,
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

    public function testCoverageDisproportionIsReportedButDoesNotWarn(): void
    {
        // sitemap has 10 URLs while the indexable surface is larger. As of 3.x
        // this coverage gap is INFO context only — it is recorded in details
        // (coverage_ratio / indexable_entities) but never raises a WARN on its
        // own, because seeded/demo catalogs made the old v3 warning fire
        // constantly. With no other issues present, the result must not warn.
        $urls = '';
        for ($i = 1; $i <= 10; $i++) {
            $urls .= "<url><loc>https://example.com/p-$i</loc></url>";
        }
        $body = '<?xml version="1.0"?><urlset>' . $urls . '</urlset>';
        $this->stubUrl('https://example.com/sitemap.xml', 200, $body);
        $this->stubUrl('https://example.com/robots.txt', 200, 'Sitemap: https://example.com/sitemap.xml');

        $result = $this->checker->check($this->store);

        $this->assertFalse($result->isWarning(), 'Coverage gap alone must not warn');
        $this->assertArrayHasKey('coverage_ratio', $result->getDetails());
        $this->assertArrayHasKey('indexable_entities', $result->getDetails());
    }
}
