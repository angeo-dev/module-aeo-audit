<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks for canonical URL tags on homepage.
 * Canonical tags prevent AI models from indexing duplicate content.
 */
class CanonicalChecker extends AbstractChecker
{
    public function getName(): string
    {
        return 'Canonical Tags — Duplicate Content Prevention';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/');

        if ($status !== 200 || empty($body)) {
            return CheckResult::warn(
                $this->getName(),
                'Could not fetch homepage to check canonical tags.',
                '',
                ['url' => $base . '/']
            );
        }

        $hasCanonical = str_contains($body, 'rel="canonical"') || str_contains($body, "rel='canonical'");
        $details      = ['url' => $base . '/'];

        if ($hasCanonical) {
            preg_match('/<link[^>]*rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $body, $matches);
            if (!empty($matches[1])) {
                $details['canonical_url'] = $matches[1];
            }
            return CheckResult::pass(
                $this->getName(),
                'Canonical tag found on homepage.',
                $details
            );
        }

        return CheckResult::warn(
            $this->getName(),
            'No canonical tag found on homepage.',
            'Add <link rel="canonical"> to all pages. This prevents AI engines from indexing duplicate URLs ' .
            '(e.g., ?SID=, ?___store= variants) as separate content.',
            $details
        );
    }
}
