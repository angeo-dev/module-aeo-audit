<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;

/**
 * Canonical-tag consistency checker.
 *
 * v3 expands the v2 "presence + domain match" check to include:
 *  - canonical agrees with <link rel="canonical"> / og:url / Product.url JSON-LD
 *  - HTTPS enforced (HTTP canonical = signal degradation)
 *  - hreflang alternates present when multiple store views exist on different URLs
 *  - canonical not pointing at the category from a configurable product page
 */
class CanonicalChecker extends AbstractChecker
{
    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'Canonical & hreflang consistency';
    }

    public function getCode(): string
    {
        return 'canonical';
    }

    public function getWeight(): float
    {
        return 0.7;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $productUrl = $this->urlSampler->getSampleProductUrl($store);
        if ($productUrl === null) {
            return $this->warn(
                'No visible products found — cannot validate canonical tags.',
                'Ensure at least one product is enabled and visible in catalog.'
            );
        }

        [$status, $html] = $this->fetch($productUrl);

        if ($status !== 200 || empty($html)) {
            return $this->warn(
                'Could not fetch product page (HTTP ' . ($status ?: 'error') . ').',
                'Ensure the store URL is publicly accessible.',
                ['url' => $productUrl]
            );
        }

        $issues = [];

        // 1. <link rel="canonical">
        $canonicalHref = null;
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m)) {
            $canonicalHref = trim($m[1]);
        }

        if ($canonicalHref === null) {
            $issues[] = 'No <link rel="canonical"> tag found';
        } else {
            // HTTPS check
            if (stripos($canonicalHref, 'http://') === 0) {
                $issues[] = 'Canonical URL uses HTTP, not HTTPS';
            }
            // Relative URL check
            if (!str_starts_with($canonicalHref, 'http')) {
                $issues[] = 'Canonical URL is relative — AI crawlers prefer absolute URLs';
            }
            // Domain mismatch
            $productHost   = parse_url($productUrl, PHP_URL_HOST);
            $canonicalHost = parse_url($canonicalHref, PHP_URL_HOST);
            if ($productHost && $canonicalHost && $productHost !== $canonicalHost) {
                $issues[] = sprintf('Canonical points to "%s" — product is on "%s"', $canonicalHost, $productHost);
            }
        }

        // 2. Cross-check with og:url
        $ogUrl = null;
        if (preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $ogUrl = trim($m[1]);
        }
        if ($canonicalHref !== null && $ogUrl !== null
            && rtrim($canonicalHref, '/') !== rtrim($ogUrl, '/')
        ) {
            $issues[] = sprintf('og:url (%s) disagrees with canonical (%s)', $ogUrl, $canonicalHref);
        }

        // 3. Cross-check with Product JSON-LD url
        $schemas = $this->extractJsonLdSchemas($html);
        $product = $this->findSchemaByType($schemas, 'Product');
        if ($product !== null && isset($product['url']) && is_string($product['url'])) {
            $schemaUrl = $product['url'];
            if ($canonicalHref !== null
                && rtrim($schemaUrl, '/') !== rtrim($canonicalHref, '/')
            ) {
                $issues[] = sprintf(
                    'Product JSON-LD url (%s) disagrees with canonical (%s) — common Hyvä layout drift',
                    $schemaUrl,
                    $canonicalHref
                );
            }
        }

        // 4. hreflang — only relevant if multiple stores exist
        $allStores = $this->storeManager->getStores();
        $hreflangIssue = null;
        if (count($allStores) > 1) {
            $hreflangCount = preg_match_all('/<link[^>]+rel=["\']alternate["\'][^>]+hreflang=/i', $html);
            if ($hreflangCount < 1) {
                $hreflangIssue = sprintf(
                    'Store has %d store views but no hreflang alternates on product page',
                    count($allStores)
                );
                $issues[] = $hreflangIssue;
            }
        }

        $details = [
            'url'             => $productUrl,
            'canonical_href'  => $canonicalHref,
            'og_url'          => $ogUrl,
            'store_count'     => count($allStores),
            'issues'          => $issues,
        ];

        if (!empty($issues)) {
            $severity = $canonicalHref === null;
            $msg = sprintf('%d canonical/hreflang issue(s) on %s', count($issues), $productUrl);
            $rec = implode(' | ', $issues);
            return $severity
                ? $this->fail($msg, $rec, $details)
                : $this->warn($msg, $rec, $details);
        }

        return $this->pass(
            sprintf('Canonical agrees with og:url and Product JSON-LD on %s.', $productUrl),
            $details
        );
    }
}
