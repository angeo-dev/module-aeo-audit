<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates MerchantReturnPolicy + OfferShippingDetails on a sampled product page.
 *
 * Since January 2026 Google and the major LLM shopping agents treat both as
 * effectively required inside Offer — without them the product loses full
 * structured-data eligibility for ChatGPT Shopping, Google AI Mode, and
 * Gemini Shopping Graph. This is the single most common reason for "schema
 * present but no AI citations".
 *
 * Also validates priceValidUntil (prevents stale-price down-ranking) and
 * itemCondition (agentic "new only" filters depend on this).
 *
 * @since 3.0.0
 */
class MerchantPoliciesChecker extends AbstractChecker
{
    private const VALID_RETURN_CATEGORIES = [
        'https://schema.org/MerchantReturnFiniteReturnWindow',
        'https://schema.org/MerchantReturnNotPermitted',
        'https://schema.org/MerchantReturnUnlimitedWindow',
        'https://schema.org/MerchantReturnUnspecified',
    ];

    public function getName(): string
    {
        return 'Merchant policies — return & shipping schema';
    }

    public function getCode(): string
    {
        return 'merchant_policies';
    }

    public function getWeight(): float
    {
        return 0.9;
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
                'No visible products found — cannot validate merchant policies.',
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

        $schemas = $this->extractJsonLdSchemas($html);
        $product = $this->findSchemaByType($schemas, 'Product');

        if ($product === null) {
            return $this->fail(
                'No Product schema found — cannot validate merchant policies.',
                'Add Product JSON-LD first via angeo/module-rich-data, then add policies.',
                ['product_url' => $productUrl]
            );
        }

        $offer = $this->extractOffer($product);
        if ($offer === null) {
            return $this->fail(
                'Product schema has no offers — cannot validate merchant policies.',
                'Add offers block with priceCurrency, price, availability, and merchant policies.',
                ['product_url' => $productUrl]
            );
        }

        $issues   = [];
        $warnings = [];

        // 1. hasMerchantReturnPolicy
        $returnPolicy = $this->extractReturnPolicy($offer, $product, $schemas);
        $returnPolicyValid = false;
        if ($returnPolicy === null) {
            $issues[] = 'Missing offers.hasMerchantReturnPolicy — required by Google & ChatGPT Shopping since Jan 2026';
        } else {
            $category = $returnPolicy['returnPolicyCategory'] ?? null;
            if ($category === null) {
                $warnings[] = 'MerchantReturnPolicy present but missing returnPolicyCategory';
            } elseif (!in_array($category, self::VALID_RETURN_CATEGORIES, true)) {
                $warnings[] = sprintf(
                    'returnPolicyCategory "%s" is not a Schema.org URI — use full URI like %s',
                    is_string($category) ? $category : 'non-string',
                    self::VALID_RETURN_CATEGORIES[0]
                );
            } else {
                $returnPolicyValid = true;
                // If finite window, merchantReturnDays should be present
                if ($category === 'https://schema.org/MerchantReturnFiniteReturnWindow'
                    && empty($returnPolicy['merchantReturnDays'])
                ) {
                    $warnings[] = 'MerchantReturnFiniteReturnWindow requires merchantReturnDays';
                }
            }
        }

        // 2. shippingDetails / OfferShippingDetails
        $shippingDetails = $offer['shippingDetails'] ?? null;
        $shippingDetailsValid = false;
        if ($shippingDetails === null) {
            $issues[] = 'Missing offers.shippingDetails — required for full structured-data eligibility';
        } elseif (!is_array($shippingDetails)) {
            $warnings[] = 'offers.shippingDetails is not a structured object';
        } else {
            $missingShippingFields = [];
            if (empty($shippingDetails['shippingRate'])) {
                $missingShippingFields[] = 'shippingRate';
            }
            if (empty($shippingDetails['deliveryTime'])) {
                $missingShippingFields[] = 'deliveryTime';
            }
            if (empty($shippingDetails['shippingDestination'])) {
                $missingShippingFields[] = 'shippingDestination';
            }
            if (!empty($missingShippingFields)) {
                $warnings[] = sprintf(
                    'OfferShippingDetails missing: %s',
                    implode(', ', $missingShippingFields)
                );
            } else {
                $shippingDetailsValid = true;
            }
        }

        // 3. priceValidUntil
        if (empty($offer['priceValidUntil'])) {
            $warnings[] = 'Missing offers.priceValidUntil — AI agents may down-rank for staleness';
        } elseif (is_string($offer['priceValidUntil'])) {
            $ts = strtotime($offer['priceValidUntil']);
            if ($ts !== false && $ts < time()) {
                $warnings[] = sprintf('priceValidUntil "%s" is in the past', $offer['priceValidUntil']);
            }
        }

        // 4. itemCondition
        if (empty($offer['itemCondition'])) {
            $warnings[] = 'Missing offers.itemCondition — agentic "new only" filters will skip this product';
        }

        $details = [
            'product_url'             => $productUrl,
            'has_return_policy'       => $returnPolicy !== null,
            'return_policy_valid'     => $returnPolicyValid,
            'has_shipping_details'    => $shippingDetails !== null,
            'shipping_details_valid'  => $shippingDetailsValid,
            'has_price_valid_until'   => !empty($offer['priceValidUntil']),
            'has_item_condition'      => !empty($offer['itemCondition']),
            'issues'                  => $issues,
            'warnings'                => $warnings,
        ];

        if (!empty($issues)) {
            return $this->fail(
                sprintf('Merchant policies — %d critical issue(s)', count($issues)),
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('Merchant policies present — %d improvement(s)', count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            'Merchant policies complete — return policy, shipping details, priceValidUntil, itemCondition all present.',
            $details
        );
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>|null
     */
    private function extractOffer(array $product): ?array
    {
        $offers = $product['offers'] ?? null;
        if (!is_array($offers)) {
            return null;
        }
        if (isset($offers['@type']) || isset($offers['price'])) {
            return $offers;
        }
        if (isset($offers[0]) && is_array($offers[0])) {
            return $offers[0];
        }
        return null;
    }

    /**
     * Return policy may be embedded in offer, in product, or as a sibling
     * MerchantReturnPolicy node referenced by @id.
     *
     * @param array<string, mixed>       $offer
     * @param array<string, mixed>       $product
     * @param list<array<string, mixed>> $schemas
     * @return array<string, mixed>|null
     */
    private function extractReturnPolicy(array $offer, array $product, array $schemas): ?array
    {
        // Inline in offer
        if (isset($offer['hasMerchantReturnPolicy']) && is_array($offer['hasMerchantReturnPolicy'])) {
            return $offer['hasMerchantReturnPolicy'];
        }
        // Inline in product
        if (isset($product['hasMerchantReturnPolicy']) && is_array($product['hasMerchantReturnPolicy'])) {
            return $product['hasMerchantReturnPolicy'];
        }
        // Sibling schema referenced by @id
        $ref = $offer['hasMerchantReturnPolicy']['@id']
            ?? $product['hasMerchantReturnPolicy']['@id']
            ?? null;
        if (is_string($ref)) {
            foreach ($schemas as $s) {
                if (($s['@id'] ?? null) === $ref) {
                    return $s;
                }
            }
        }
        // Standalone MerchantReturnPolicy in graph
        return $this->findSchemaByType($schemas, 'MerchantReturnPolicy');
    }
}
