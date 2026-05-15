<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\ProductSchemaChecker;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductSchemaCheckerTest extends TestCase
{
    /** @var Curl */
    private Curl|MockObject $curl;
    /** @var CollectionFactory */
    private CollectionFactory|MockObject $productCollectionFactory;
    /** @var ProductSchemaChecker */
    private ProductSchemaChecker $checker;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->productCollectionFactory = $this->createMock(CollectionFactory::class);

        $this->checker = new ProductSchemaChecker(
            $this->curl,
            $this->productCollectionFactory
        );
    }

    public function testGetCodeAndWeight(): void
    {
        $this->assertSame('product_schema', $this->checker->getCode());
        $this->assertSame(1.0, $this->checker->getWeight());
        $this->assertNotEmpty($this->checker->getName());
        $this->assertSame('composer require angeo/module-rich-data', $this->checker->getFixCommand());
    }

    public function testWarnWhenNoProductsAvailable(): void
    {
        $collection = $this->createMock(\Magento\Catalog\Model\ResourceModel\Product\Collection::class);
        $select = $this->createMock(\Magento\Framework\DB\Select::class);

        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $select->method('orderRand')->willReturnSelf();

        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->method('getId')->willReturn(null);
        $collection->method('getFirstItem')->willReturn($product);

        $this->productCollectionFactory->method('create')->willReturn($collection);

        $result = $this->checker->check('https://example.com');

        $this->assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
    }
}
