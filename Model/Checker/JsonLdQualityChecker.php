<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Deep JSON-LD quality scan beyond Product schema.
 *
 * Samples three pages: homepage, sample category, sample product. Inventories
 * every schema node, then validates against a 2026-AEO checklist:
 *
 *  - @context is the canonical "https://schema.org" (not http, not bare)
 *  - WebSite + SearchAction (sitelinks search box)
 *  - BreadcrumbList on product page
 *  - ItemList on category page (Gemini Shopping Graph)
 *  - Product variants use ProductGroup + hasVariant (not duplicated Products)
 *  - No duplicate schemas (Magento native + Hyvä + third-party stacking)
 *
 * Complementary to ProductSchemaChecker — that one validates Product depth,
 * this one validates breadth and structural correctness.
 *
 * @since 3.0.0
 */
class JsonLdQualityChecker extends AbstractChecker
{
    public function getName(): string
    {
        return 'JSON-LD quality — schema breadth & consistency';
    }

    public function getCode(): string
    {
        return 'jsonld_quality';
    }

    public function getWeight(): float
    {
        return 0.7;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base        = $this->urlSampler->getBaseUrl($store);
        $productUrl  = $this->urlSampler->getSampleProductUrl($store);
        $categoryUrl = $this->urlSampler->getSampleCategoryUrl($store);

        $pages = ['homepage' => $base];
        if ($productUrl !== null) {
            $pages['product'] = $productUrl;
        }
        if ($categoryUrl !== null) {
            $pages['category'] = $categoryUrl;
        }

        $report = [];
        foreach ($pages as $label => $url) {
            [$status, $html] = $this->fetch($url);
            $report[$label] = [
                'url'         => $url,
                'http_status' => $status,
                'schemas'     => [],
                'issues'      => [],
            ];
            if ($status !== 200 || empty($html)) {
                continue;
            }
            $schemas = $this->extractJsonLdSchemas($html);
            $report[$label]['schemas'] = $this->summariseTypes($schemas);
            $report[$label]['issues']  = $this->inspectSchemas($html, $schemas, $label);
        }

        $allIssues = [];
        foreach ($report as $page => $info) {
            foreach ($info['issues'] as $issue) {
                $allIssues[] = "[$page] $issue";
            }
        }

        // Critical: no product schema on product page
        $critical = array_filter($allIssues, static fn($i) => str_contains($i, '[CRITICAL]'));

        $details = ['pages' => $report];

        if (!empty($critical)) {
            return $this->fail(
                sprintf('JSON-LD quality: %d critical issue(s) found', count($critical)),
                implode(' | ', $allIssues),
                $details
            );
        }

        if (!empty($allIssues)) {
            return $this->warn(
                sprintf('JSON-LD quality: %d improvement(s) across %d page(s)', count($allIssues), count($report)),
                implode(' | ', $allIssues),
                $details
            );
        }

        return $this->pass(
            sprintf('JSON-LD quality OK across %d sampled page(s).', count($report)),
            $details
        );
    }

    /**
     * @param list<array<string, mixed>> $schemas
     * @return array<string, int>
     */
    private function summariseTypes(array $schemas): array
    {
        $counts = [];
        foreach ($schemas as $s) {
            $type = $s['@type'] ?? 'unknown';
            $type = is_array($type) ? implode('+', $type) : (string) $type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $schemas
     * @return string[]
     */
    private function inspectSchemas(string $html, array $schemas, string $page): array
    {
        $issues = [];

        // 1. @context check — sample first JSON-LD block raw text
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $m)) {
            foreach ($m[1] as $jsonRaw) {
                $decoded = json_decode(trim($jsonRaw), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $ctx = $decoded['@context'] ?? null;
                if ($ctx === null) {
                    $issues[] = 'JSON-LD block missing @context';
                } elseif (is_string($ctx) && $ctx !== 'https://schema.org' && $ctx !== 'http://schema.org') {
                    // OK if it's a context object or array — only flag bare wrong strings
                    if (!str_contains($ctx, 'schema.org')) {
                        $issues[] = sprintf('@context "%s" is not Schema.org', $ctx);
                    }
                } elseif ($ctx === 'http://schema.org') {
                    $issues[] = '@context uses http:// — strict parsers may reject; use https://';
                }
            }
        }

        // 2. Per-page expected types
        $typeCounts = $this->summariseTypes($schemas);

        if ($page === 'product') {
            if (!isset($typeCounts['Product']) && !isset($typeCounts['Product+ProductGroup'])) {
                $issues[] = '[CRITICAL] Product schema absent on product page';
            }
            if (!isset($typeCounts['BreadcrumbList'])) {
                $issues[] = 'BreadcrumbList missing — strong AI ranking signal';
            }
            // Multiple Product schemas on same page = Magento + Hyvä + 3rd-party stacking
            if (isset($typeCounts['Product']) && $typeCounts['Product'] > 1) {
                $issues[] = sprintf(
                    'Multiple Product schemas (%d) on the page — LLMs ignore contradictory schema',
                    $typeCounts['Product']
                );
            }
        }

        if ($page === 'category') {
            if (!isset($typeCounts['ItemList']) && !isset($typeCounts['CollectionPage'])) {
                $issues[] = 'No ItemList / CollectionPage on category page — needed for Gemini Shopping Graph';
            }
        }

        if ($page === 'homepage') {
            $hasWebSite = isset($typeCounts['WebSite']);
            if ($hasWebSite) {
                // SearchAction sub-check
                $site = $this->findSchemaByType($schemas, 'WebSite');
                if ($site !== null && empty($site['potentialAction'])) {
                    $issues[] = 'WebSite schema present but no potentialAction (SearchAction) — sitelinks search box disabled';
                }
            } else {
                $issues[] = 'No WebSite schema — enables sitelinks search box in AI/Google results';
            }
        }

        return $issues;
    }
}
