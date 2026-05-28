<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Checks for AI-readable product feed (ChatGPT Shopping / Gemini product cards).
 *
 * Detection order:
 *  1. angeo/module-openai-product-feed-api  — REST endpoint
 *  2. angeo/module-openai-product-feed      — CSV file
 *  3. Generic fallback paths (third-party feeds)
 *  4. /.well-known/ai-plugin.json           — OpenAI merchant registration signal
 */
class ProductFeedChecker extends AbstractChecker
{
    private const ANGEO_FEED_PATHS = [
        '/angeo/openai_feed/default.csv'       => 'Angeo feed (default store)',
        '/angeo/openai_feed/base.csv'          => 'Angeo feed (base store)',
        '/media/angeo/openai_feed/default.csv' => 'Angeo feed via media (default)',
        '/media/angeo/openai_feed/base.csv'    => 'Angeo feed via media (base)',
    ];

    private const GENERIC_FEED_PATHS = [
        '/openai-product-feed.csv' => 'CSV feed (root)',
        '/feeds/products.csv'      => 'CSV feed (alternate)',
        '/feeds/products.json'     => 'JSON feed',
        '/feed.json'               => 'JSON Feed standard',
        '/catalog/product/feed'    => 'Generic catalog feed',
    ];

    private const REST_API_PATH  = '/rest/V1/angeo/product_feeds';
    private const AI_PLUGIN_PATH = '/.well-known/ai-plugin.json';

    public function getName(): string
    {
        return 'AI product feed — ChatGPT Shopping / Gemini';
    }

    public function getCode(): string
    {
        return 'ai_product_feed';
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_FEED;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-openai-product-feed angeo/module-openai-product-feed-api';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base    = $this->urlSampler->getBaseUrl($store);
        $details = [];

        // 1. angeo REST API
        $restStatus          = $this->statusCode($base . self::REST_API_PATH);
        $restFound           = in_array($restStatus, [200, 401, 403], true);
        $details['rest_api'] = ['path' => self::REST_API_PATH, 'status' => $restStatus, 'found' => $restFound];

        // 2. angeo CSV file
        $angeoFeed = null;
        foreach (self::ANGEO_FEED_PATHS as $path => $label) {
            if ($this->statusCode($base . $path) === 200) {
                $angeoFeed = ['path' => $path, 'label' => $label];
                break;
            }
        }

        // 3. Generic feeds
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

        // 4. ai-plugin.json
        $details['ai_plugin_json'] = ($this->statusCode($base . self::AI_PLUGIN_PATH) === 200);

        if ($foundFeed === null && !$restFound) {
            return $this->fail(
                'No AI product feed found.',
                'Install angeo/module-openai-product-feed and run: bin/magento angeo:product-feed:generate'
                . ' — or install angeo/module-openai-product-feed-api for the full REST API feed.',
                $details
            );
        }

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
