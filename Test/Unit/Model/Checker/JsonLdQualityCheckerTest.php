<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\JsonLdQualityChecker;
use PHPUnit\Framework\TestCase;

class JsonLdQualityCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private JsonLdQualityChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->urlSampler->method('getSampleProductUrl')->willReturn('https://example.com/product');
        $this->urlSampler->method('getSampleCategoryUrl')->willReturn('https://example.com/category');

        $this->checker = new JsonLdQualityChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailsWhenProductPageHasNoProductSchema(): void
    {
        $this->stubUrl('https://example.com', 200, '<html></html>');
        $this->stubUrl('https://example.com/product', 200, '<html></html>');
        $this->stubUrl('https://example.com/category', 200, '<html></html>');

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed(), 'No Product schema on product page should FAIL');
        $this->assertStringContainsString('CRITICAL', $result->getRecommendation());
    }

    public function testWarnsOnMissingBreadcrumbAndItemList(): void
    {
        $productHtml = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => 'P',
        ]);
        $categoryHtml = '<html></html>';
        $homeHtml = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'url'      => 'https://example.com',
            'potentialAction' => ['@type' => 'SearchAction'],
        ]);
        $this->stubUrl('https://example.com', 200, $homeHtml);
        $this->stubUrl('https://example.com/product', 200, $productHtml);
        $this->stubUrl('https://example.com/category', 200, $categoryHtml);

        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $combined = $result->getMessage() . ' ' . $result->getRecommendation();
        $this->assertStringContainsString('BreadcrumbList', $combined);
        $this->assertStringContainsString('ItemList', $combined);
    }

    public function testHttpContextIsFlagged(): void
    {
        $productHtml = $this->wrapJsonLd([
            '@context' => 'http://schema.org',
            '@type'    => 'Product',
            'name'     => 'P',
        ]);
        $this->stubUrl('https://example.com', 200, '<html></html>');
        $this->stubUrl('https://example.com/product', 200, $productHtml);
        $this->stubUrl('https://example.com/category', 200, '<html></html>');

        $result = $this->checker->check($this->store);
        $combined = $result->getRecommendation();
        $this->assertStringContainsString('http://', $combined);
    }

    public function testDuplicateProductSchemasFlagged(): void
    {
        $product1 = $this->wrapJsonLd(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'A']);
        $product2 = $this->wrapJsonLd(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'A']);
        $breadcrumb = $this->wrapJsonLd(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList']);
        $productHtml = $product1 . $product2 . $breadcrumb;

        $this->stubUrl('https://example.com', 200, $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'url'      => 'https://example.com',
            'potentialAction' => ['@type' => 'SearchAction'],
        ]));
        $this->stubUrl('https://example.com/product', 200, $productHtml);
        $this->stubUrl('https://example.com/category', 200, $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'ItemList',
        ]));

        $result = $this->checker->check($this->store);
        $combined = $result->getRecommendation();
        $this->assertStringContainsString('Multiple Product', $combined);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function wrapJsonLd(array $schema): string
    {
        return '<script type="application/ld+json">' . json_encode($schema) . '</script>';
    }
}
