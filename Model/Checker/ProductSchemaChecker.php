<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Validates Product JSON-LD schema on a live product page.
 *
 * Improvements over v1:
 * - Fetches a real random product URL (not homepage)
 * - Full JSON-LD parser via AbstractChecker::extractJsonLdSchemas()
 * - Validates offers.availability (critical for ChatGPT Shopping)
 * - Validates offers.priceCurrency
 * - Detects Hyvä theme (removes default Magento microdata)
 * - Checks for AggregateRating (improves AI citation score)
 * - Checks image property (needed for AI product cards)
 */
class ProductSchemaChecker extends AbstractChecker
{
    private const REQUIRED_FIELDS       = ['name', 'description', 'offers', 'image'];
    private const REQUIRED_OFFER_FIELDS = ['price', 'priceCurrency', 'availability'];

    public function __construct(
        Curl $curl,
        private readonly CollectionFactory $productCollectionFactory,
    ) {
        parent::__construct($curl);
    }

    public function getName(): string  { return 'Product schema — JSON-LD structured data'; }
    public function getCode(): string  { return 'product_schema'; }
    public function getWeight(): float { return 1.0; }
    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }


    public function check(string $baseUrl): CheckResult
    {
        $productUrl = $this->getSampleProductUrl();

        if ($productUrl === null) {
            return $this->warn(
                'No visible products found — cannot validate Product schema.',
                'Ensure at least one product is enabled and visible in catalog.'
            );
        }

        [$status, $html] = $this->fetch($productUrl);

        if ($status !== 200 || empty($html)) {
            return $this->warn(
                'Could not fetch product page (HTTP ' . ($status ?: 'error') . ').',
                'Ensure the store URL is publicly accessible.',
                ['product_url' => $productUrl]
            );
        }

        $isHyva   = $this->detectHyva($html);
        $schemas  = $this->extractJsonLdSchemas($html);
        $product  = $this->findSchemaByType($schemas, 'Product');

        $details = [
            'product_url'   => $productUrl,
            'hyva_detected' => $isHyva,
        ];

        if ($product === null) {
            return $this->fail(
                sprintf(
                    'No Product JSON-LD schema found on %s.%s',
                    $productUrl,
                    $isHyva ? ' (Hyvä theme — microdata removed by default)' : ''
                ),
                $isHyva
                    ? 'Add Product JSON-LD via layout XML override. Guide: angeo.dev/hyva-theme-aeo-ai-visibility/'
                    : 'Add @type:Product JSON-LD schema. See: schema.org/Product',
                $details
            );
        }

        // Validate required top-level fields
        $missingFields = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($product[$field])) {
                $missingFields[] = $field;
            }
        }

        // Deep offers validation
        $missingOfferFields = [];
        $offers = $product['offers'] ?? null;
        if (is_array($offers)) {
            $offerData = isset($offers['@type']) ? $offers : ($offers[0] ?? []);
            foreach (self::REQUIRED_OFFER_FIELDS as $field) {
                if (empty($offerData[$field])) {
                    $missingOfferFields[] = "offers.$field";
                }
            }
        }

        $allMissing = array_merge($missingFields, $missingOfferFields);

        // Bonus checks
        $hasRating     = $this->findSchemaByType($schemas, 'AggregateRating') !== null
                         || isset($product['aggregateRating']);
        $hasBreadcrumb = $this->findSchemaByType($schemas, 'BreadcrumbList') !== null;

        $details = array_merge($details, [
            'schema_type'         => $product['@type'] ?? 'unknown',
            'missing_fields'      => $allMissing,
            'has_aggregate_rating' => $hasRating,
            'has_breadcrumb'      => $hasBreadcrumb,
        ]);

        if (!empty($allMissing)) {
            return $this->warn(
                sprintf('Product schema found but missing: %s', implode(', ', $allMissing)),
                'Add missing fields — especially offers.availability for ChatGPT Shopping.',
                $details
            );
        }

        return $this->pass(
            sprintf(
                'Valid Product JSON-LD on %s — all required fields present%s%s.',
                $productUrl,
                $hasRating ? ', AggregateRating present' : '',
                $hasBreadcrumb ? ', BreadcrumbList present' : ''
            ),
            $details
        );
    }

    private function getSampleProductUrl(): ?string
    {
        $collection = $this->productCollectionFactory->create();
        $collection
            ->addAttributeToSelect(['url_key', 'status', 'visibility'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', [
                'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
            ])
            ->addUrlRewrite()
            ->setPageSize(1)
            ->getSelect()->orderRand();

        $product = $collection->getFirstItem();

        if (!$product->getId()) {
            return null;
        }

        try {
            return $product->getProductUrl();
        } catch (\Exception) {
            return null;
        }
    }

    private function detectHyva(string $html): bool
    {
        return str_contains($html, 'hyva') || str_contains($html, 'Hyvä') || str_contains($html, 'hyva-theme');
    }
}
