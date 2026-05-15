<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Laminas\Uri\UriFactory;

/**
 * Validates canonical tags for AI duplicate content prevention.
 *
 * Magento has NO canonical option for homepage — only for categories
 * and products. So we check:
 *  1. Product page canonical (most important — directly tied to config)
 *  2. Category page canonical
 *  3. Homepage canonical (WARN only if missing — not a Magento config issue)
 *
 * PASS if products + categories both have canonical.
 * WARN if homepage is missing (expected — no Magento config for it).
 * FAIL only if product/category canonical is missing AND config path confirmed.
 */
class CanonicalChecker extends AbstractChecker
{
    /**
     * Get human-readable check name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Canonical tags — duplicate content prevention';
    }
    /**
     * Get unique machine-readable check code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return 'canonical';
    }
    /**
     * Get check weight (0.0–1.0).
     *
     * @return float
     */
    public function getWeight(): float
    {
        return 0.6;
    }

    /**
     * @param string $baseUrl
     * @return CheckResult
     */
    public function check(string $baseUrl): CheckResult
    {
        $base    = $this->normalizeBase($baseUrl);
        $details = ['base_url' => $base];

        // 1. Find a real product URL to check
        $productCanonical = $this->checkProductCanonical($base, $details);

        // 2. Find a real category URL to check
        $categoryCanonical = $this->checkCategoryCanonical($base, $details);

        // 3. Homepage canonical — informational only
        $homepageCanonical = $this->checkHomepageCanonical($base, $details);

        $issues   = [];
        $warnings = [];

        // Product canonical is the most important
        if ($productCanonical === false) {
            $issues[] = 'Product pages missing canonical tag'
                . ' — enable: Stores → Config → Catalog → SEO → Use Canonical Link Meta Tag For Products';
        } elseif ($productCanonical === null) {
            $warnings[] = 'Could not find a product page to verify canonical tag';
        }

        // Category canonical
        if ($categoryCanonical === false) {
            $issues[] = 'Category pages missing canonical tag'
                . ' — enable: Stores → Config → Catalog → SEO → Use Canonical Link Meta Tag For Categories';
        } elseif ($categoryCanonical === null) {
            $warnings[] = 'Could not find a category page to verify canonical tag';
        }

        // Homepage — just informational, no FAIL
        if ($homepageCanonical === false) {
            $warnings[] = 'Homepage has no canonical tag — this is normal in Magento'
                . ' (no built-in option). Add manually via CMS or theme if needed';
        }

        if (!empty($issues)) {
            return $this->fail(
                $issues[0],
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf(
                    'Canonical tags %s — %d note(s)',
                    ($productCanonical !== null || $categoryCanonical !== null)
                        ? 'present on key pages' : 'status unclear',
                    count($warnings)
                ),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            'Canonical tags present on product and category pages.',
            $details
        );
    }

    /**
     * Find first product URL from sitemap or homepage links and check canonical.
     * Returns: true = present, false = missing, null = could not check
     */
    private function checkProductCanonical(string $base, array &$details): ?bool
    {
        $url = $this->findProductUrl($base);
        if (!$url) {
            return null;
        }

        [$status, $html] = $this->fetch($url);
        if ($status !== 200 || empty($html)) {
            return null;
        }

        $canonical = $this->extractCanonical($html);
        $details['product_url']       = $url;
        $details['product_canonical'] = $canonical;

        if ($canonical === null) {
            return false;
        }

        // Domain mismatch
        if (UriFactory::factory($canonical)->getHost() !== UriFactory::factory($base)->getHost()) {
            $details['product_canonical_mismatch'] = true;
        }

        return true;
    }

    /**
     * Find first category URL and check canonical.
     */
    private function checkCategoryCanonical(string $base, array &$details): ?bool
    {
        $url = $this->findCategoryUrl($base);
        if (!$url) {
            return null;
        }

        [$status, $html] = $this->fetch($url);
        if ($status !== 200 || empty($html)) {
            return null;
        }

        $canonical = $this->extractCanonical($html);
        $details['category_url']       = $url;
        $details['category_canonical'] = $canonical;

        return $canonical !== null;
    }

    /**
     * Check homepage canonical — no FAIL, informational only.
     */
    private function checkHomepageCanonical(string $base, array &$details): ?bool
    {
        [$status, $html] = $this->fetch($base . '/');
        if ($status !== 200 || empty($html)) {
            return null;
        }

        $canonical = $this->extractCanonical($html);
        $details['homepage_canonical'] = $canonical;

        return $canonical !== null;
    }

    /**
     * Try to find a product URL via sitemap or homepage links.
     */
    private function findProductUrl(string $base): ?string
    {
        // Try sitemap first
        [$status, $xml] = $this->fetch($base . '/sitemap.xml');
        if ($status === 200 && !empty($xml)) {
            // Look for a URL with .html or /catalog/product pattern
            if (preg_match_all('/<loc>(https?:\/\/[^<]+)<\/loc>/', $xml, $m)) {
                foreach ($m[1] as $url) {
                    if (str_contains($url, '.html') ||
                        str_contains($url, '/product/') ||
                        str_contains($url, '/p/')) {
                        return $url;
                    }
                }
                // Fallback: last URL in sitemap (likely a product)
                $urls = $m[1];
                if (count($urls) > 2) {
                    return end($urls);
                }
            }
        }

        // Try homepage links
        [$hStatus, $html] = $this->fetch($base . '/');
        if ($hStatus === 200 && !empty($html)) {
            if (preg_match_all('/href=["\'](' . preg_quote($base, '/') . '[^"\']+\.html)["\']/', $html, $m)) {
                return $m[1][0] ?? null;
            }
        }

        return null;
    }

    /**
     * Try to find a category URL.
     */
    private function findCategoryUrl(string $base): ?string
    {
        [$status, $xml] = $this->fetch($base . '/sitemap.xml');
        if ($status === 200 && !empty($xml)) {
            if (preg_match_all('/<loc>(https?:\/\/[^<]+)<\/loc>/', $xml, $m)) {
                foreach ($m[1] as $url) {
                    // Categories usually don't have product-like patterns
                    if (!str_contains($url, '.html') &&
                        $url !== $base . '/' &&
                        $url !== $base) {
                        return $url;
                    }
                }
            }
        }

        // Fallback: navigate to homepage and grab first nav link
        [$hStatus, $html] = $this->fetch($base . '/');
        if ($hStatus === 200 && !empty($html)) {
            if (preg_match(
                '/<nav[^>]*>.*?href=["\'](' . preg_quote($base, '/') . '[^"\']+)["\'].*?<\/nav>/is',
                $html,
                $m
            )) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * @param mixed $html
     * @return ?string
     */
    private function extractCanonical(string $html): ?string
    {
        $patterns = [
            '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/i',
            '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\'][^>]*>/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
