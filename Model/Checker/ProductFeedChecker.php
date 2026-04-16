<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks for AI-readable product feed (ChatGPT Shopping / Gemini product cards).
 *
 * Improvements over v1:
 * - Correct angeo feed paths (CSV file, not /var/ directory)
 * - Checks /.well-known/ai-plugin.json (OpenAI merchant registration)
 * - Checks angeo REST API endpoint if file feed not found
 * - Distinguishes "feed exists but not registered" from "no feed at all"
 */
class ProductFeedChecker extends AbstractChecker
{
    private const FEED_PATHS = [
        '/openai-product-feed.csv' => 'Angeo CSV feed',
        '/feeds/products.csv'      => 'CSV feed (alternate)',
        '/feeds/products.json'     => 'JSON feed',
        '/feed.json'               => 'JSON Feed standard',
        '/catalog/product/feed'    => 'Generic catalog feed',
    ];

    private const REST_API_PATH    = '/rest/V1/angeo/product-feed';
    private const AI_PLUGIN_PATH   = '/.well-known/ai-plugin.json';

    public function getName(): string  { return 'AI product feed — ChatGPT Shopping / Gemini'; }
    public function getCode(): string  { return 'ai_product_feed'; }
    public function getWeight(): float { return 1.0; }

    public function check(string $baseUrl): CheckResult
    {
        $base    = $this->normalizeBase($baseUrl);
        $details = ['checked_paths' => array_keys(self::FEED_PATHS)];

        // Find a working feed
        $foundFeed = null;
        foreach (self::FEED_PATHS as $path => $label) {
            if ($this->statusCode($base . $path) === 200) {
                $foundFeed = ['path' => $path, 'label' => $label];
                break;
            }
        }

        // Check REST API (angeo/module-openai-product-feed-api)
        $restStatus              = $this->statusCode($base . self::REST_API_PATH);
        $details['rest_api']     = $restStatus !== 404;

        // Check OpenAI merchant registration
        $aiPluginStatus          = $this->statusCode($base . self::AI_PLUGIN_PATH);
        $details['ai_plugin_json'] = $aiPluginStatus === 200;

        if ($foundFeed === null && !$details['rest_api']) {
            return $this->fail(
                'No AI product feed found.',
                'Install angeo/module-openai-product-feed and run: bin/magento angeo:product-feed:generate',
                $details
            );
        }

        if ($foundFeed === null && $details['rest_api']) {
            return $this->warn(
                'REST API feed endpoint found but no file-based feed — may not be registered with OpenAI.',
                'Register your feed endpoint at chatgpt.com/merchants.',
                $details
            );
        }

        $details['feed'] = $foundFeed;

        if (!$details['ai_plugin_json']) {
            return $this->warn(
                sprintf('Feed found at %s but /.well-known/ai-plugin.json missing.', $foundFeed['path']),
                'Register at chatgpt.com/merchants and add /.well-known/ai-plugin.json for full ChatGPT Shopping integration.',
                $details
            );
        }

        return $this->pass(
            sprintf('AI product feed found at %s — OpenAI merchant registration present.', $foundFeed['path']),
            $details
        );
    }
}
