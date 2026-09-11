<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Plugin;

use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\ResourceModel\BotHit;
use Angeo\AeoAudit\Service\BotRegistry;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Frontend-area plugin that records AI crawler visits.
 *
 * Hot-path discipline — this runs on EVERY frontend cache-miss request:
 *  1. Single config flag check (cached by Magento's config layer).
 *  2. One header read + one stripos scan over ~15 short tokens for
 *     non-bot traffic (the 99.9% case) — microseconds, no allocation.
 *  3. Only on a positive match: one atomic counter UPSERT.
 *  4. Everything wrapped in try/catch — instrumentation must NEVER
 *     break or slow a storefront response in a visible way.
 *
 * Registered in etc/frontend/di.xml only, so admin and API requests carry
 * zero overhead. Requests served from full-page cache never reach PHP and
 * are therefore not counted — the checker reports this coverage limit.
 *
 * PRIVACY: nothing about the request is persisted except the bot code,
 * its class, the store id and a date-bucketed counter. No IP, no URL,
 * no raw user agent string.
 *
 * @since 4.0.0
 */
class RecordBotHit
{
    public function __construct(
        private readonly Config                $config,
        private readonly BotRegistry           $botRegistry,
        private readonly BotHit                $botHitResource,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array{0: RequestInterface}
     */
    public function beforeDispatch(FrontControllerInterface $subject, RequestInterface $request): array
    {
        try {
            if (!$request instanceof HttpRequest) {
                return [$request];
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            if (!$this->config->isInstrumentationEnabled($storeId)) {
                return [$request];
            }

            $userAgent = (string) $request->getHeader('User-Agent');
            $botCode   = $this->botRegistry->classifyUserAgent($userAgent);
            if ($botCode === null) {
                return [$request];
            }

            $bot = $this->botRegistry->get($botCode);
            $this->botHitResource->recordHit($botCode, $bot['class'] ?? '', $storeId);
        } catch (\Throwable) {
            // Never let instrumentation interfere with the storefront.
            return [$request];
        }

        return [$request];
    }
}
