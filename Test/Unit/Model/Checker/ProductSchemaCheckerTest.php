<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\ProductSchemaChecker;
use PHPUnit\Framework\TestCase;

class ProductSchemaCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private ProductSchemaChecker $checker;
    private const PRODUCT_URL = 'https://example.com/product';

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->urlSampler->method('getSampleProductUrl')->willReturn(self::PRODUCT_URL);
        $this->checker = new ProductSchemaChecker($this->httpCache, $this->urlSampler);
    }

    public function testWarnsWhenNoProducts(): void
    {
        $sampler = $this->createMock(\Angeo\AeoAudit\Service\StoreUrlSampler::class);
        $sampler->method('getBaseUrl')->willReturn('https://example.com');
        $sampler->method('getSampleProductUrl')->willReturn(null);
        $checker = new ProductSchemaChecker($this->httpCache, $sampler);
        $result = $checker->check($this->store);
        $this->assertTrue($result->isWarning());
    }

    public function testFailWhenNoSchema(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, '<html></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testPassWithCompleteSchema(): void
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => 'Widget',
            'description' => 'A widget',
            'image'       => 'https://example.com/img.jpg',
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => '10.00',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
            ],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    public function testWarnsWhenOfferAvailabilityMissing(): void
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => 'Widget',
            'description' => 'A widget',
            'image'       => 'https://example.com/img.jpg',
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => '10.00',
                'priceCurrency' => 'USD',
                // availability missing
            ],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('availability', $result->getMessage());
    }

    public function testGraphRecursionFindsProduct(): void
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'WebSite', 'url' => 'https://example.com'],
                [
                    '@type'       => 'Product',
                    'name'        => 'W',
                    'description' => 'desc',
                    'image'       => 'img',
                    'offers'      => [
                        '@type'         => 'Offer',
                        'price'         => '1',
                        'priceCurrency' => 'USD',
                        'availability'  => 'https://schema.org/InStock',
                    ],
                ],
            ],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
    }
}
