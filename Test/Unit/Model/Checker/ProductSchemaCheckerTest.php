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

    /**
     * angeo/module-rich-data 2.x publishes a configurable product as
     * ProductGroup inside @graph, with offers on each variant.
     */
    public function testPassWithProductGroupInGraph(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, $this->productGroupHtml());
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
        $this->assertStringContainsString('ProductGroup', $result->getMessage());
        $this->assertSame(1, $result->getDetails()['variant_count']);
    }

    public function testWarnsWhenProductGroupVariantOfferIncomplete(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, $this->productGroupHtml(['availability' => null]));
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('hasVariant[].offers.availability', $result->getMessage());
    }

    public function testWarnsWhenProductGroupHasNoVariants(): void
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'ProductGroup',
            'name'        => 'Hoodie',
            'description' => 'desc',
            'image'       => 'img',
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('hasVariant', $result->getMessage());
    }

    public function testPassWithAggregateOffer(): void
    {
        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Product',
            'name'        => 'Bundle',
            'description' => 'desc',
            'image'       => 'img',
            'offers'      => [
                '@type'         => 'AggregateOffer',
                'lowPrice'      => '10.00',
                'highPrice'     => '20.00',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
            ],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    /**
     * @param array<string, mixed> $offerOverride
     */
    private function productGroupHtml(array $offerOverride = []): string
    {
        $offer = array_filter(array_merge([
            '@type'         => 'Offer',
            'price'         => '54.00',
            'priceCurrency' => 'USD',
            'availability'  => 'https://schema.org/InStock',
        ], $offerOverride), static fn ($v) => $v !== null);

        $schema = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'Shop'],
                [
                    '@type'          => 'ProductGroup',
                    '@id'            => self::PRODUCT_URL . '#product-group',
                    'name'           => 'Hero Hoodie',
                    'description'    => 'desc',
                    'image'          => 'img',
                    'productGroupID' => 'MH07',
                    'hasVariant'     => [[
                        '@type'                => 'Product',
                        'sku'                  => 'MH07-XS-Black',
                        'inProductGroupWithID' => 'MH07',
                        'offers'               => $offer,
                    ]],
                ],
            ],
        ];

        // Same flags as angeo/module-rich-data's JsonEncoder.
        $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        return '<script type="application/ld+json">' . json_encode($schema, $flags) . '</script>';
    }
}
