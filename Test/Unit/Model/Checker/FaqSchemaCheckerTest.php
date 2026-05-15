<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\FaqSchemaChecker;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FaqSchemaCheckerTest extends TestCase
{
    /** @var Curl */
    private Curl|MockObject $curl;
    /** @var CmsPageCollectionFactory */
    private CmsPageCollectionFactory|MockObject $cmsPageCollectionFactory;
    /** @var FaqSchemaChecker */
    private FaqSchemaChecker $checker;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->cmsPageCollectionFactory = $this->createMock(CmsPageCollectionFactory::class);

        $this->checker = new FaqSchemaChecker(
            $this->curl,
            $this->cmsPageCollectionFactory
        );
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('faq_schema', $this->checker->getCode());
        $this->assertSame(0.5, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
        $this->assertSame('composer require angeo/module-rich-data', $this->checker->getFixCommand());
    }

    public function testWarnWhenNoFaqSchemaFound(): void
    {
        $collection = $this->createMock(\Magento\Cms\Model\ResourceModel\Page\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->cmsPageCollectionFactory->method('create')->willReturn($collection);

        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('<html><body>No schema here</body></html>');

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
    }

    public function testPassWhenFaqPageSchemaFound(): void
    {
        $collection = $this->createMock(\Magento\Cms\Model\ResourceModel\Page\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->cmsPageCollectionFactory->method('create')->willReturn($collection);

        $faqHtml = '<html><script type="application/ld+json">'
            . '{"@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Q?"}]}'
            . '</script></html>';

        $this->curl->method('setTimeout')->willReturnSelf();
        $this->curl->method('setOption')->willReturnSelf();
        $this->curl->method('addHeader')->willReturnSelf();
        $this->curl->method('get')->willReturnSelf();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($faqHtml);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_PASS, $result->getStatus());
    }
}
