<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates /llms.txt per llmstxt.org spec.
 *
 * v3 adds store-locale awareness:
 *  - Verifies file is per-host (subdomain stores need separate llms.txt — spec)
 *  - Checks declared currency matches store currency
 *  - Checks declared language matches store locale
 */
class LlmsTxtChecker extends AbstractChecker
{
    private const MIN_CONTENT_LENGTH = 100;
    private const MAX_BYTES          = 524288;
    private const STALE_DAYS         = 7;
    private const MAX_LINK_CHECK     = 3;

    public function getName(): string
    {
        return 'llms.txt — AI content map';
    }

    public function getCode(): string
    {
        return 'llms_txt';
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);
        $url  = $base . '/llms.txt';

        [$status, $body, $headers] = $this->fetchWithHeaders($url);

        // 1. Existence
        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'llms.txt not found (HTTP ' . ($status ?: 'error') . ').',
                'Install angeo/module-llms-txt and run: bin/magento angeo:llms:generate',
                ['url' => $url]
            );
        }

        $issues   = [];
        $warnings = [];
        $size     = strlen($body);
        $lines    = explode("\n", $body);

        // 2. H1 title
        $firstLine = '';
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t !== '') {
                $firstLine = $t;
                break;
            }
        }
        if (!str_starts_with($firstLine, '# ') || strlen($firstLine) < 4) {
            $issues[] = 'First non-empty line must be H1: "# Store Name" (llmstxt.org spec)';
        }

        // 3. Description after H1
        $hasDescription = $this->hasDescriptionAfterH1($lines);
        if (!$hasDescription) {
            $warnings[] = 'No description after H1 — add a brief store description for AI context';
        }

        // 4. H2 sections
        preg_match_all('/^##\s+(.+)$/m', $body, $sectionMatches);
        $sectionCount  = count($sectionMatches[0]);
        $sectionTitles = $sectionMatches[1] ?? [];
        if ($sectionCount === 0) {
            $issues[] = 'No H2 sections — add ## Products, ## Categories etc.';
        }

        // 5. Markdown links
        preg_match_all('/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', $body, $linkMatches);
        $linkCount = count($linkMatches[0]);
        $linkUrls  = $linkMatches[2] ?? [];
        if ($linkCount === 0) {
            $issues[] = 'No markdown links — llms.txt without links gives AI no navigation targets';
        }

        // 5b. Cross-host links (subdomain stores must keep llms.txt self-referential)
        $baseHost = (string) parse_url($base, PHP_URL_HOST);
        $foreignHostLinks = 0;
        foreach ($linkUrls as $linkUrl) {
            $linkHost = (string) parse_url($linkUrl, PHP_URL_HOST);
            if ($linkHost !== '' && $baseHost !== '' && $linkHost !== $baseHost) {
                $foreignHostLinks++;
            }
        }
        if ($foreignHostLinks > 0) {
            $warnings[] = sprintf(
                '%d link(s) point to a different host than %s — per-subdomain llms.txt should self-reference',
                $foreignHostLinks,
                $baseHost
            );
        }

        // 6. eCommerce sections
        $hasEcom = $this->hasEcommerceSection($sectionTitles);
        if (!$hasEcom && $sectionCount > 0) {
            $warnings[] = 'No Products/Categories section — ecommerce stores benefit from explicit catalog sections';
        }

        // 7. Metadata — and verify it matches the store
        $metadataResult = $this->checkMetadataMatchesStore($body, $store);
        if (!$metadataResult['has_metadata']) {
            $warnings[] = 'No currency or language metadata — add store locale info for AI disambiguation';
        }
        foreach ($metadataResult['mismatches'] as $mismatch) {
            $warnings[] = $mismatch;
        }

        // 8. Duplicate URLs
        $uniqueUrls = array_unique($linkUrls);
        $dupCount   = $linkCount - count($uniqueUrls);
        if ($dupCount > 0) {
            $warnings[] = sprintf('%d duplicate URL(s) in links', $dupCount);
        }

        // 9. Freshness
        $lastModified = $headers['last-modified'] ?? null;
        if ($lastModified) {
            $modTime = strtotime($lastModified);
            if ($modTime && (time() - $modTime) > (self::STALE_DAYS * 86400)) {
                $days = (int) round((time() - $modTime) / 86400);
                $warnings[] = sprintf(
                    'llms.txt is %d days old — enable cron or run: bin/magento angeo:llms:generate',
                    $days
                );
            }
        }

        // 10. File size
        if ($size < self::MIN_CONTENT_LENGTH) {
            $issues[] = sprintf('File too small (%d bytes) — looks like a stub', $size);
        }
        if ($size > self::MAX_BYTES) {
            $warnings[] = sprintf(
                'File is %.1fKB — split into llms.txt + llms-full.txt per spec',
                $size / 1024
            );
        }

        // 11. Dead link check
        $deadLinks = [];
        foreach (array_slice($uniqueUrls, 0, self::MAX_LINK_CHECK) as $linkUrl) {
            $ls = $this->statusCode($linkUrl);
            if ($ls >= 400 || $ls === 0) {
                $deadLinks[] = $linkUrl . ' (HTTP ' . $ls . ')';
            }
        }
        if (!empty($deadLinks)) {
            $warnings[] = 'Dead link(s): ' . implode(', ', $deadLinks);
        }

        // 12. llms-full.txt
        $hasFullTxt = ($this->statusCode($base . '/llms-full.txt') === 200);

        $details = [
            'url'              => $url,
            'size_bytes'       => $size,
            'sections'         => $sectionCount,
            'section_titles'   => $sectionTitles,
            'links'            => $linkCount,
            'duplicate_urls'   => $dupCount,
            'foreign_host_links' => $foreignHostLinks,
            'has_metadata'     => $metadataResult['has_metadata'],
            'has_ecom_section' => $hasEcom,
            'last_modified'    => $lastModified,
            'llms_full_txt'    => $hasFullTxt,
            'dead_links'       => $deadLinks,
            'store_locale'     => $metadataResult['store_locale'],
            'store_currency'   => $metadataResult['store_currency'],
        ];

        if (!empty($issues)) {
            return $this->fail(
                sprintf('llms.txt has %d critical issue(s): %s', count($issues), $issues[0]),
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf(
                    'llms.txt valid (%d sections, %d links) — %d improvement(s)',
                    $sectionCount,
                    $linkCount,
                    count($warnings)
                ),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf(
                'llms.txt valid — %d section(s), %d link(s)%s%s.',
                $sectionCount,
                $linkCount,
                $hasFullTxt  ? ', llms-full.txt present' : '',
                $metadataResult['has_metadata'] ? ', store-matching metadata' : ''
            ),
            $details
        );
    }

    /**
     * @param string[] $lines
     */
    private function hasDescriptionAfterH1(array $lines): bool
    {
        $afterH1 = false;
        foreach ($lines as $line) {
            $t = trim($line);
            if (!$afterH1 && str_starts_with($t, '# ')) {
                $afterH1 = true;
                continue;
            }
            if ($afterH1 && $t !== '' && !str_starts_with($t, '#')) {
                return true;
            }
            if ($afterH1 && str_starts_with($t, '## ')) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param string[] $sectionTitles
     */
    private function hasEcommerceSection(array $sectionTitles): bool
    {
        $ecomKeywords = ['product', 'categor', 'catalog', 'shop', 'collection'];
        foreach ($sectionTitles as $title) {
            foreach ($ecomKeywords as $kw) {
                if (stripos($title, $kw) !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array{has_metadata: bool, mismatches: string[], store_locale: string, store_currency: string}
     */
    private function checkMetadataMatchesStore(string $body, StoreInterface $store): array
    {
        $storeLocale   = $this->resolveStoreLocale($store);
        $storeCurrency = $this->resolveStoreCurrency($store);

        $bodyLower    = strtolower($body);
        $hasMetadata  = false;
        $mismatches   = [];

        foreach (['currency', 'language', 'lang:', 'store url', 'base url', 'locale'] as $kw) {
            if (str_contains($bodyLower, $kw)) {
                $hasMetadata = true;
                break;
            }
        }

        if ($hasMetadata && $storeCurrency !== '') {
            $currencyLower = strtolower($storeCurrency);
            // Did llms.txt name *some* currency that's not ours?
            if (str_contains($bodyLower, 'currency')
                && !str_contains($bodyLower, $currencyLower)
            ) {
                $mismatches[] = sprintf(
                    'llms.txt declares currency but does not mention store currency %s',
                    $storeCurrency
                );
            }
        }

        if ($hasMetadata && $storeLocale !== '') {
            $shortLocale = substr($storeLocale, 0, 2); // en_US → en
            if ((str_contains($bodyLower, 'language') || str_contains($bodyLower, 'locale'))
                && !str_contains($bodyLower, strtolower($shortLocale))
                && !str_contains($bodyLower, strtolower($storeLocale))
            ) {
                $mismatches[] = sprintf(
                    'llms.txt declares language but does not mention store locale %s',
                    $storeLocale
                );
            }
        }

        return [
            'has_metadata'   => $hasMetadata,
            'mismatches'     => $mismatches,
            'store_locale'   => $storeLocale,
            'store_currency' => $storeCurrency,
        ];
    }

    private function resolveStoreLocale(StoreInterface $store): string
    {
        // ScopeConfig path 'general/locale/code' is the canonical source.
        // The Store object exposes getConfig() for backend-scoped config reads.
        if (method_exists($store, 'getConfig')) {
            $locale = (string) $store->getConfig('general/locale/code');
            if ($locale !== '') {
                return $locale;
            }
        }
        return '';
    }

    private function resolveStoreCurrency(StoreInterface $store): string
    {
        if (method_exists($store, 'getCurrentCurrencyCode')) {
            $cur = (string) $store->getCurrentCurrencyCode();
            if ($cur !== '') {
                return $cur;
            }
        }
        if (method_exists($store, 'getDefaultCurrencyCode')) {
            return (string) $store->getDefaultCurrencyCode();
        }
        return '';
    }
}
