<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Validates canonical tags on homepage.
 *
 * Improvements over v1:
 * - Detects domain mismatch (canonical points to wrong domain = misconfiguration)
 * - Handles both attribute orders (<link rel= href=> and <link href= rel=>)
 * - Downgrades missing canonical to WARN instead of FAIL (some stores use
 *   other dedup strategies) but keeps it visible
 */
class CanonicalChecker extends AbstractChecker
{
    public function getName(): string  { return 'Canonical tags — duplicate content prevention'; }
    public function getCode(): string  { return 'canonical'; }
    public function getWeight(): float { return 0.6; }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $html] = $this->fetch($base . '/');

        if ($status !== 200 || empty($html)) {
            return $this->warn(
                'Could not fetch homepage for canonical check (HTTP ' . ($status ?: 'error') . ').',
                '',
                ['url' => $base . '/']
            );
        }

        $canonical = $this->extractCanonical($html);
        $details   = ['url' => $base . '/'];

        if ($canonical === null) {
            return $this->warn(
                'No canonical tag found on homepage.',
                'Enable canonical tags: Stores → Configuration → Catalog → Search Engine Optimization → Use Canonical Link Meta Tag.',
                $details
            );
        }

        $details['canonical_url'] = $canonical;

        // Domain mismatch check
        $canonicalHost = parse_url($canonical, PHP_URL_HOST);
        $baseHost      = parse_url($base, PHP_URL_HOST);

        if ($canonicalHost !== $baseHost) {
            return $this->warn(
                sprintf('Canonical points to a different host: %s (store host: %s)', $canonicalHost, $baseHost),
                'Verify your Base URL in Stores → Configuration → General → Web → Base URLs.',
                $details
            );
        }

        return $this->pass('Canonical tag present: ' . $canonical, $details);
    }

    private function extractCanonical(string $html): ?string
    {
        // Handle both attribute orderings
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
