<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks for AI-readable product feed (ChatGPT Shopping / Gemini product cards).
 *
 * Detection order:
 * 1. angeo/module-openai-product-feed-api  — REST endpoint /rest/V1/angeo/product_feeds (GET → 200)
 * 2. angeo/module-openai-product-feed      — CSV file at /angeo/openai_feed/<store_code>.csv
 * 3. Generic fallback paths (third-party feeds)
 * 4. /.well-known/ai-plugin.json           — OpenAI merchant registration signal
 */
class ProductFeedChecker extends AbstractChecker
{
    // ── angeo/module-openai-product-feed: var/angeo/openai_feed/<code>.csv ──
    // Served at pub/angeo/openai_feed/<code>.csv after symlink/webserver config
    // or via media URL. We check both common store codes as well as generic paths.
    private const ANGEO_FEED_PATHS = [
        '/angeo/openai_feed/default.csv' => 'Angeo feed (default store)',
        '/angeo/openai_feed/base.csv'    => 'Angeo feed (base store)',
        '/media/angeo/openai_feed/default.csv' => 'Angeo feed via media (default)',
        '/media/angeo/openai_feed/base.csv'    => 'Angeo feed via media (base)',
    ];

    // ── Generic third-party feed paths ────────────────────────────────────
    private const GENERIC_FEED_PATHS = [
        '/openai-product-feed.csv' => 'CSV feed (root)',
        '/feeds/products.csv'      => 'CSV feed (alternate)',
        '/feeds/products.json'     => 'JSON feed',
        '/feed.json'               => 'JSON Feed standard',
        '/catalog/product/feed'    => 'Generic catalog feed',
    ];

    // ── angeo/module-openai-product-feed-api REST endpoint ────────────────
    // GET /rest/V1/angeo/product_feeds returns 200 with feed list
    private const REST_API_PATH  = '/rest/V1/angeo/product_feeds';

    private const AI_PLUGIN_PATH = '/.well-known/ai-plugin.json';

    /**
     * Get human-readable check name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AI product feed — ChatGPT Shopping / Gemini';
    }
    /**
     * Get unique machine-readable check code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return 'ai_product_feed';
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
        return 'composer require angeo/module-openai-product-feed angeo/module-openai-product-feed-api';
    }

    /**
     * @param string $baseUrl
     * @return CheckResult
     */
    public function check(string $baseUrl): CheckResult
    {
        $base    = $this->normalizeBase($baseUrl);
        $details = [];

        // 1. Check angeo REST API (module-openai-product-feed-api)
        $restStatus          = $this->statusCode($base . self::REST_API_PATH);
        $restFound           = in_array($restStatus, [200, 401, 403], true); // any non-404 = route exists
        $details['rest_api'] = ['path' => self::REST_API_PATH, 'status' => $restStatus, 'found' => $restFound];

        // 2. Check angeo CSV file (module-openai-product-feed)
        $angeoFeed = null;
        foreach (self::ANGEO_FEED_PATHS as $path => $label) {
            if ($this->statusCode($base . $path) === 200) {
                $angeoFeed = ['path' => $path, 'label' => $label];
                break;
            }
        }

        // 3. Check generic third-party feed paths
        $genericFeed = null;
        if ($angeoFeed === null && !$restFound) {
            foreach (self::GENERIC_FEED_PATHS as $path => $label) {
                if ($this->statusCode($base . $path) === 200) {
                    $genericFeed = ['path' => $path, 'label' => $label];
                    break;
                }
            }
        }

        $foundFeed = $angeoFeed ?? $genericFeed;

        // 4. OpenAI merchant registration
        $aiPluginStatus          = $this->statusCode($base . self::AI_PLUGIN_PATH);
        $details['ai_plugin_json'] = $aiPluginStatus === 200;

        // ── Evaluate ──────────────────────────────────────────────────────

        // Nothing found at all
        if ($foundFeed === null && !$restFound) {
            return $this->fail(
                'No AI product feed found.',
                'Install angeo/module-openai-product-feed and run: bin/magento angeo:product-feed:generate'
                . ' — or install angeo/module-openai-product-feed-api for the full REST API feed.',
                $details
            );
        }

        // REST API found (module-openai-product-feed-api installed) — best state
        if ($restFound) {
            $details['feed'] = ['path' => self::REST_API_PATH, 'label' => 'Angeo REST API feed'];
            if (!$details['ai_plugin_json']) {
                return $this->warn(
                    sprintf('Angeo REST API feed detected at %s.', self::REST_API_PATH)
                    . ' /.well-known/ai-plugin.json not found — merchant not registered with OpenAI yet.',
                    'Apply at chatgpt.com/merchants with your feed endpoint to complete ChatGPT Shopping registration.',
                    $details
                );
            }
            return $this->pass(
                sprintf('Angeo REST API feed at %s — OpenAI merchant registration present.', self::REST_API_PATH),
                $details
            );
        }

        // File-based feed found (module-openai-product-feed or third-party)
        $details['feed'] = $foundFeed;
        if (!$details['ai_plugin_json']) {
            return $this->warn(
                sprintf('Feed found at %s but /.well-known/ai-plugin.json missing.', $foundFeed['path']),
                'Register at chatgpt.com/merchants and add /.well-known/ai-plugin.json'
                    . ' for full ChatGPT Shopping integration.',
                $details
            );
        }

        return $this->pass(
            sprintf('AI product feed found at %s — OpenAI merchant registration present.', $foundFeed['path']),
            $details
        );
    }
}
