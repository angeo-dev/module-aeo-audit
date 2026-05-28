<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates Open Graph tags on a sampled product page.
 *
 * og:title, og:description, og:image, og:url, og:type are the basic five
 * that AI agents and social platforms rely on for product card rendering.
 */
class OpenGraphChecker extends AbstractChecker
{
    private const REQUIRED_TAGS = ['og:title', 'og:description', 'og:image', 'og:url', 'og:type'];

    public function getName(): string
    {
        return 'Open Graph tags — social/AI cards';
    }

    public function getCode(): string
    {
        return 'open_graph';
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
                'No visible products found — cannot validate Open Graph tags.',
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

        $found   = [];
        $missing = [];
        foreach (self::REQUIRED_TAGS as $tag) {
            $pattern = sprintf('/<meta[^>]+property=["\']%s["\'][^>]+content=["\']([^"\']*)["\']/i', preg_quote($tag, '/'));
            if (preg_match($pattern, $html, $m)) {
                $found[$tag] = trim($m[1]);
            } else {
                $missing[] = $tag;
            }
        }

        $details = [
            'url'     => $productUrl,
            'found'   => array_keys($found),
            'missing' => $missing,
        ];

        if (!empty($missing)) {
            return $this->warn(
                sprintf('Missing Open Graph tags: %s', implode(', ', $missing)),
                'Add the missing <meta property="og:..."> tags to <head>.',
                $details
            );
        }

        $description = $found['og:description'] ?? '';
        if (strlen($description) < 50) {
            return $this->warn(
                'All Open Graph tags present but og:description is short (<50 chars).',
                'Expand og:description to 80–160 chars for AI/social card optimisation.',
                $details
            );
        }

        return $this->pass(
            sprintf('All %d Open Graph tags present, description %d chars.', count(self::REQUIRED_TAGS), strlen($description)),
            $details
        );
    }
}
