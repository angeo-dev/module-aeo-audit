<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Validates /llms.txt per llmstxt.org spec.
 *
 * Checks:
 *  1.  HTTP 200 and non-empty body
 *  2.  H1 title on first non-empty line (mandatory per spec)
 *  3.  Description paragraph after H1
 *  4.  At least one H2 section
 *  5.  Markdown links present
 *  6.  eCommerce sections: Products / Categories
 *  7.  Metadata: currency / language / base URL
 *  8.  Duplicate URLs in links
 *  9.  File freshness via Last-Modified header (warn if > 7 days)
 * 10.  File size sanity (stub < 100B, oversized > 512KB)
 * 11.  HEAD-check of first 3 links for dead URLs
 * 12.  /llms-full.txt availability (bonus)
 */
class LlmsTxtChecker extends AbstractChecker
{
    private const MIN_CONTENT_LENGTH = 100;
    private const MAX_BYTES          = 524288;
    private const STALE_DAYS         = 7;
    private const MAX_LINK_CHECK     = 3;

    /**
     * Get human-readable check name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'llms.txt — AI content map';
    }
    /**
     * Get unique machine-readable check code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return 'llms_txt';
    }
    /**
     * Get check weight (0.0–1.0).
     *
     * @return float
     */
    public function getWeight(): float
    {
        return 1.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    /**
     * @param string $baseUrl
     * @return CheckResult
     */
    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
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
        $afterH1 = false;
        $hasDescription = false;
        foreach ($lines as $line) {
            $t = trim($line);
            if (!$afterH1 && str_starts_with($t, '# ')) {
                $afterH1 = true;
                continue;
            }
            if ($afterH1 && $t !== '' && !str_starts_with($t, '#')) {
                $hasDescription = true;
                break;
            }
            if ($afterH1 && str_starts_with($t, '## ')) {
                break;
            }
        }
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

        // 6. eCommerce sections
        $ecomKeywords = ['product', 'categor', 'catalog', 'shop', 'collection'];
        $hasEcom      = false;
        foreach ($sectionTitles as $title) {
            foreach ($ecomKeywords as $kw) {
                if (stripos($title, $kw) !== false) {
                    $hasEcom = true;
                    break 2;
                }
            }
        }
        if (!$hasEcom && $sectionCount > 0) {
            $warnings[] = 'No Products/Categories section — ecommerce stores benefit from explicit catalog sections';
        }

        // 7. Metadata
        $hasMetadata = false;
        foreach (['currency', 'language', 'lang:', 'store url', 'base url', 'locale'] as $kw) {
            if (stripos($body, $kw) !== false) {
                $hasMetadata = true;
                break;
            }
        }
        if (!$hasMetadata) {
            $warnings[] = 'No currency or language metadata — add store locale info for AI disambiguation';
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

        // 11. Dead link check (HEAD first N)
        $deadLinks = [];
        foreach (array_slice($uniqueUrls, 0, self::MAX_LINK_CHECK) as $linkUrl) {
            [$ls] = $this->fetch($linkUrl);
            if ($ls >= 400 || $ls === 0) {
                $deadLinks[] = $linkUrl . ' (HTTP ' . $ls . ')';
            }
        }
        if (!empty($deadLinks)) {
            $warnings[] = 'Dead link(s): ' . implode(', ', $deadLinks);
        }

        // 12. llms-full.txt
        [$fullStatus] = $this->fetch($base . '/llms-full.txt');
        $hasFullTxt   = ($fullStatus === 200);

        $details = [
            'url'              => $url,
            'size_bytes'       => $size,
            'sections'         => $sectionCount,
            'section_titles'   => $sectionTitles,
            'links'            => $linkCount,
            'duplicate_urls'   => $dupCount,
            'has_metadata'     => $hasMetadata,
            'has_ecom_section' => $hasEcom,
            'last_modified'    => $lastModified,
            'llms_full_txt'    => $hasFullTxt,
            'dead_links'       => $deadLinks,
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
                $hasMetadata ? ', metadata present'       : ''
            ),
            $details
        );
    }

    /**
     * @return array{int, string, array<string, string>}
     */
    /**
     * Fetch URL and return status, body, and response headers.
     *
     * Uses the injected Curl client to avoid discouraged PHP functions.
     *
     * @param string $url
     * @return array{0: int, 1: string, 2: array}
     */
    private function fetchWithHeaders(string $url): array
    {
        try {
            $this->curl->setTimeout(10);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 3);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            $this->curl->addHeader('User-Agent', self::USER_AGENT);
            $this->curl->get($url);

            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();

            // Normalise headers into a lowercase-key associative array
            $rawHeaders = method_exists($this->curl, 'getHeaders')
                ? (array) $this->curl->getHeaders()
                : [];
            $headers = [];
            foreach ($rawHeaders as $k => $v) {
                $headers[strtolower((string)$k)] = $v;
            }

            return [$status, $body, $headers];
        } catch (\Exception $e) {
            return [0, '', []];
        }
    }
}
