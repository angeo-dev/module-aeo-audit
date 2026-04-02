<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks whether an AI-ready product feed is publicly accessible.
 * Looks for angeo feed paths and common alternatives (JSON, CSV).
 */
class ProductFeedChecker extends AbstractChecker
{
    private const FEED_CANDIDATES = [
        '/var/angeo/openai_feed/'   => 'Angeo OpenAI feed directory',
        '/feed.json'                => 'JSON Feed (standard)',
        '/catalog/product/feed'     => 'Generic catalog feed',
        '/api/products'             => 'Generic products API',
    ];

    public function getName(): string
    {
        return 'AI Product Feed — ChatGPT/Gemini Discovery';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base    = $this->normalizeBase($baseUrl);
        $found   = [];
        $missing = [];

        foreach (self::FEED_CANDIDATES as $path => $label) {
            $url      = $base . $path;
            [$status] = $this->fetch($url);
            if ($status === 200) {
                $found[] = $label . ' (' . $url . ')';
            } else {
                $missing[] = $label;
            }
        }

        $details = [
            'found'   => $found,
            'missing' => $missing,
        ];

        if (!empty($found)) {
            return CheckResult::pass(
                $this->getName(),
                sprintf('%d AI product feed(s) found: %s', count($found), implode(', ', $found)),
                $details
            );
        }

        return CheckResult::fail(
            $this->getName(),
            'No AI-readable product feed found.',
            'Install angeo/module-openai-product-feed and run: ' .
            'bin/magento angeo:product-feed:generate. ' .
            'A structured product feed is required for ChatGPT Shopping and Gemini product recommendations.',
            $details
        );
    }
}
