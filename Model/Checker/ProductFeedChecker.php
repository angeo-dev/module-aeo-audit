<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Checks for an AI-readable product feed (ACP discovery / Gemini product cards).
 *
 * The surface this signal measures changed in 2026. ACP shipped in September
 * 2025 built around Instant Checkout; OpenAI retired that flow on 24 March 2026
 * and refocused ACP on product discovery — merchants push catalog feeds and
 * promotions over the protocol and keep checkout on their own store. The
 * 2026-04-17 revision formalised that shift, adding cart, feed, orders,
 * authentication and MCP transport to the specification.
 *
 * So a feed is no longer a nice-to-have alongside a checkout integration: for
 * ACP it is now the integration. A store with no machine-readable feed cannot
 * be surfaced by ChatGPT at all, regardless of how good its on-page markup is.
 *
 * Detection order:
 *  1. angeo/module-openai-product-feed-api  — REST endpoint
 *  2. angeo/module-openai-product-feed      — CSV file
 *  3. Generic fallback paths (third-party feeds)
 *
 * 4.3.0 removed the /.well-known/ai-plugin.json probe. That file belonged to
 * ChatGPT plugins, which OpenAI retired in 2024; it was never part of ACP and
 * never a merchant-registration signal. Whether a feed is registered with a
 * merchant programme cannot be seen from outside the store, so the check says
 * so instead of guessing.
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

        if ($foundFeed === null && !$restFound) {
            return $this->fail(
                'No AI product feed found.',
                'Install angeo/module-openai-product-feed and run: bin/magento angeo:product-feed:generate'
                . ' — or install angeo/module-openai-product-feed-api for the full REST API feed.',
                $details
            );
        }

        $registrationNote = 'Submit the feed in each merchant programme you want to appear in'
            . ' (for example OpenAI\'s merchant programme or Google Merchant Center).'
            . ' Registration cannot be checked from outside the store.';

        if ($restFound) {
            $details['feed'] = ['path' => self::REST_API_PATH, 'label' => 'Angeo REST API feed'];
            if ($restStatus !== 200) {
                // 401/403: installed and protected. Normal when the feed is
                // pushed to a merchant programme; noted, not penalised.
                $details['note_auth'] = sprintf(
                    'The endpoint returns HTTP %d to anonymous callers. An agent that pulls the feed needs credentials.',
                    $restStatus
                );
            }
            return $this->pass(
                sprintf(
                    'Angeo REST API feed at %s%s.',
                    self::REST_API_PATH,
                    $restStatus === 200 ? '' : sprintf(' (protected, HTTP %d)', $restStatus)
                ),
                $details + ['note' => $registrationNote]
            );
        }

        $details['feed'] = $foundFeed;

        return $this->pass(
            sprintf('AI product feed found at %s.', $foundFeed['path']),
            $details + ['note' => $registrationNote]
        );
    }
}
