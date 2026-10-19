<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates Product JSON-LD schema on a live product page.
 *
 * v3 uses StoreUrlSampler — checker no longer holds its own CollectionFactory.
 * The sampled product URL is shared with MerchantPoliciesChecker and other
 * product-page checkers via the request-scoped sampler cache.
 */
class ProductSchemaChecker extends AbstractChecker
{
    private const REQUIRED_FIELDS       = ['name', 'description', 'offers', 'image'];
    private const REQUIRED_OFFER_FIELDS = ['price', 'priceCurrency', 'availability'];

    public function getName(): string
    {
        return 'Product schema — JSON-LD structured data';
    }

    public function getCode(): string
    {
        return 'product_schema';
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $productUrl = $this->urlSampler->getSampleProductUrl($store);

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

        $isHyva  = $this->detectHyva($html);
        $schemas = $this->extractJsonLdSchemas($html);
        $product = $this->findProductSchema($schemas);

        $details = [
            'product_url'   => $productUrl,
            'hyva_detected' => $isHyva,
        ];

        if ($product === null) {
            return $this->fail(
                sprintf(
                    'No Product JSON-LD schema (Product or ProductGroup) found on %s.%s',
                    $productUrl,
                    $isHyva ? ' (Hyvä theme — microdata removed by default)' : ''
                ),
                $isHyva
                    ? 'Add Product JSON-LD via layout XML override. Guide: angeo.dev/hyva-theme-aeo-ai-visibility/'
                    : 'Add @type:Product JSON-LD schema. See: schema.org/Product',
                $details
            );
        }

        $isGroup  = $this->isProductGroup($product);
        $variants = $this->getVariants($product);
        $offer    = $this->extractProductOffer($product);

        $missingFields = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($field === 'offers') {
                if ($offer === null) {
                    $missingFields[] = $isGroup ? 'offers (no offers on the group or its variants)' : 'offers';
                }
                continue;
            }
            if (empty($product[$field])) {
                $missingFields[] = $field;
            }
        }

        if ($isGroup && $variants === []) {
            $missingFields[] = 'hasVariant';
        }

        $missingOfferFields = [];
        if ($offer !== null) {
            $prefix = ($isGroup && empty($product['offers'])) ? 'hasVariant[].offers' : 'offers';
            $isAggregate = ($offer['@type'] ?? null) === 'AggregateOffer';
            foreach (self::REQUIRED_OFFER_FIELDS as $field) {
                // An AggregateOffer carries lowPrice / highPrice instead of price.
                if ($field === 'price' && $isAggregate && !empty($offer['lowPrice'])) {
                    continue;
                }
                if (empty($offer[$field])) {
                    $missingOfferFields[] = "$prefix.$field";
                }
            }
        }

        $allMissing = array_merge($missingFields, $missingOfferFields);

        $hasRating     = $this->findSchemaByType($schemas, 'AggregateRating') !== null
                         || isset($product['aggregateRating']);
        $hasBreadcrumb = $this->findSchemaByType($schemas, 'BreadcrumbList') !== null;

        $details = array_merge($details, [
            'schema_type'          => $product['@type'] ?? 'unknown',
            'variant_count'        => count($variants),
            'missing_fields'       => $allMissing,
            'has_aggregate_rating' => $hasRating,
            'has_breadcrumb'       => $hasBreadcrumb,
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
                'Valid %s JSON-LD on %s — all required fields present%s%s.',
                $isGroup ? sprintf('ProductGroup (%d variants)', count($variants)) : 'Product',
                $productUrl,
                $hasRating ? ', AggregateRating present' : '',
                $hasBreadcrumb ? ', BreadcrumbList present' : ''
            ),
            $details
        );
    }

    private function detectHyva(string $html): bool
    {
        return str_contains($html, 'hyva') || str_contains($html, 'Hyvä') || str_contains($html, 'hyva-theme');
    }
}
