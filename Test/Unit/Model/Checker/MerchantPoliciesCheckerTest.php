<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\MerchantPoliciesChecker;
use PHPUnit\Framework\TestCase;

class MerchantPoliciesCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private MerchantPoliciesChecker $checker;
    private const PRODUCT_URL = 'https://example.com/sample-product';

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->urlSampler->method('getSampleProductUrl')->willReturn(self::PRODUCT_URL);
        $this->checker = new MerchantPoliciesChecker($this->httpCache, $this->urlSampler);
    }

    public function testWarnsWhenNoProductsAvailable(): void
    {
        $sampler = $this->createMock(\Angeo\AeoAudit\Service\StoreUrlSampler::class);
        $sampler->method('getBaseUrl')->willReturn('https://example.com');
        $sampler->method('getSampleProductUrl')->willReturn(null);
        $checker = new MerchantPoliciesChecker($this->httpCache, $sampler);

        $result = $checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('No visible products', $result->getMessage());
    }

    public function testFailWhenNoProductSchema(): void
    {
        $this->stubUrl(self::PRODUCT_URL, 200, '<html><body>No schema here</body></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('No Product schema', $result->getMessage());
    }

    public function testFailWhenNoMerchantPolicy(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Widget',
            'offers'   => [
                '@type'         => 'Offer',
                'price'         => '10.00',
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
            ],
        ]);
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
        $this->assertStringContainsString(
            'MerchantReturnPolicy',
            $result->getRecommendation() . ' ' . $result->getMessage()
        );
    }

    public function testPassesWithFullPolicies(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Widget',
            'offers'   => [
                '@type'                   => 'Offer',
                'price'                   => '10.00',
                'priceCurrency'           => 'USD',
                'availability'            => 'https://schema.org/InStock',
                'priceValidUntil'         => '2099-12-31',
                'itemCondition'           => 'https://schema.org/NewCondition',
                'hasMerchantReturnPolicy' => [
                    '@type'                  => 'MerchantReturnPolicy',
                    'returnPolicyCategory'   => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                    'merchantReturnDays'     => 30,
                ],
                'shippingDetails' => [
                    '@type'               => 'OfferShippingDetails',
                    'shippingRate'        => ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => 'USD'],
                    'deliveryTime'        => ['@type' => 'ShippingDeliveryTime'],
                    'shippingDestination' => ['@type' => 'DefinedRegion'],
                ],
            ],
        ]);
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage()
            . ' | ' . $result->getRecommendation());
    }

    public function testWarnsOnPastPriceValidUntil(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Widget',
            'offers'   => [
                '@type'                   => 'Offer',
                'price'                   => '10.00',
                'priceCurrency'           => 'USD',
                'availability'            => 'https://schema.org/InStock',
                'priceValidUntil'         => '2020-01-01',
                'itemCondition'           => 'https://schema.org/NewCondition',
                'hasMerchantReturnPolicy' => [
                    '@type'                => 'MerchantReturnPolicy',
                    'returnPolicyCategory' => 'https://schema.org/MerchantReturnUnspecified',
                ],
                'shippingDetails' => [
                    '@type'               => 'OfferShippingDetails',
                    'shippingRate'        => ['@type' => 'MonetaryAmount'],
                    'deliveryTime'        => ['@type' => 'ShippingDeliveryTime'],
                    'shippingDestination' => ['@type' => 'DefinedRegion'],
                ],
            ],
        ]);
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('past', $result->getRecommendation()
            . ' ' . $result->getMessage());
    }

    public function testWarnsWhenReturnPolicyCategoryUriInvalid(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'Widget',
            'offers'   => [
                '@type'                   => 'Offer',
                'price'                   => '10.00',
                'priceCurrency'           => 'USD',
                'availability'            => 'https://schema.org/InStock',
                'priceValidUntil'         => '2099-01-01',
                'itemCondition'           => 'https://schema.org/NewCondition',
                'hasMerchantReturnPolicy' => [
                    '@type'                => 'MerchantReturnPolicy',
                    'returnPolicyCategory' => 'FiniteReturnWindow', // Wrong — no URI
                ],
                'shippingDetails' => [
                    '@type'               => 'OfferShippingDetails',
                    'shippingRate'        => ['@type' => 'MonetaryAmount'],
                    'deliveryTime'        => ['@type' => 'ShippingDeliveryTime'],
                    'shippingDestination' => ['@type' => 'DefinedRegion'],
                ],
            ],
        ]);
        $this->stubUrl(self::PRODUCT_URL, 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('Schema.org', $result->getRecommendation()
            . ' ' . $result->getMessage());
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function wrapJsonLd(array $schema): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode($schema) . '</script></head><body></body></html>';
    }
}
