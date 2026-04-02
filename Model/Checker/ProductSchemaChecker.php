<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Fetches a sample product page and checks for Product schema JSON-LD.
 */
class ProductSchemaChecker extends AbstractChecker
{
    public function __construct(
        Curl $curl,
        private readonly CollectionFactory $productCollectionFactory
    ) {
        parent::__construct($curl);
    }

    public function getName(): string
    {
        return 'Product Schema — JSON-LD Structured Data';
    }

    public function check(string $baseUrl): CheckResult
    {
        $productUrl = $this->getSampleProductUrl();

        if ($productUrl === null) {
            return CheckResult::warn(
                $this->getName(),
                'No enabled products found to test schema markup.',
                'Add at least one enabled, visible product with a URL key to the catalog.'
            );
        }

        [$status, $body] = $this->fetch($productUrl);

        if ($status !== 200 || empty($body)) {
            return CheckResult::warn(
                $this->getName(),
                'Could not fetch sample product page to check schema.',
                'Ensure sample product URL is publicly accessible.',
                ['tested_url' => $productUrl]
            );
        }

        $hasProductSchema  = $this->hasSchemaType($body, 'Product');
        $hasPriceSchema    = str_contains($body, '"price"') || str_contains($body, '"offers"');
        $hasNameSchema     = str_contains($body, '"name"');
        $hasBreadcrumb     = $this->hasSchemaType($body, 'BreadcrumbList');

        $details = [
            'tested_url'       => $productUrl,
            'Product type'     => $hasProductSchema ? 'yes' : 'no',
            'price/offers'     => $hasPriceSchema ? 'yes' : 'no',
            'name'             => $hasNameSchema ? 'yes' : 'no',
            'BreadcrumbList'   => $hasBreadcrumb ? 'yes' : 'no',
        ];

        if (!$hasProductSchema) {
            return CheckResult::fail(
                $this->getName(),
                'No Product JSON-LD schema found on product page.',
                'Install a Magento SEO extension that adds Product schema, or implement ' .
                '"@type":"Product" JSON-LD in your theme. AI engines rely on this for accurate product data.',
                $details
            );
        }

        if (!$hasPriceSchema || !$hasNameSchema) {
            return CheckResult::warn(
                $this->getName(),
                'Product schema found but missing price or name fields.',
                'Ensure your Product schema includes "name", "offers" (with "price" and "priceCurrency"), and "sku".',
                $details
            );
        }

        return CheckResult::pass(
            $this->getName(),
            'Product JSON-LD schema found with price and name fields.',
            $details
        );
    }

    private function getSampleProductUrl(): ?string
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['url_key', 'status', 'visibility'])
            ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', [
                'in' => [
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_IN_CATALOG,
                    \Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH,
                ],
            ])
            ->setPageSize(1)
            ->setCurPage(1);

        $product = $collection->getFirstItem();
        if ($product->getId() === null) {
            return null;
        }

        try {
            return $product->getProductUrl();
        } catch (\Exception) {
            return null;
        }
    }

    private function hasSchemaType(string $html, string $type): bool
    {
        return str_contains($html, '"@type":"' . $type . '"')
            || str_contains($html, '"@type": "' . $type . '"');
    }
}
