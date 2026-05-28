<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Core Web Vitals via Google's CrUX (Chrome User Experience) API.
 *
 * CrUX gives field data for LCP / INP / CLS. The API requires an API key —
 * it's not enabled by default. Admins set the key via:
 *   Stores → Configuration → Angeo AEO → CrUX API Key
 *
 * Without a key the checker returns a non-failing INFO result. With a key, it
 * fetches the latest 28-day p75 metrics and grades them against Google's
 * "good" thresholds (LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1).
 *
 * Category: external_api — runs slower, may incur quota.
 *
 * @since 3.0.0
 */
class CoreWebVitalsChecker extends AbstractChecker
{
    public const CONFIG_PATH_API_KEY = 'angeo_aeo/crux/api_key';
    private const CRUX_ENDPOINT      = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

    private const THRESHOLDS = [
        'largest_contentful_paint' => ['good' => 2500, 'unit' => 'ms'],
        'interaction_to_next_paint' => ['good' => 200, 'unit' => 'ms'],
        'cumulative_layout_shift'  => ['good' => 0.1, 'unit' => ''],
    ];

    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'Core Web Vitals — CrUX field data';
    }

    public function getCode(): string
    {
        return 'core_web_vitals';
    }

    public function getWeight(): float
    {
        return 0.5;
    }

    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_EXTERNAL_API;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $apiKey = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $store->getCode()
        );

        if ($apiKey === '') {
            return $this->warn(
                'CrUX API key not configured — Core Web Vitals not measured.',
                'Get a free key at console.cloud.google.com and set under '
                    . 'Stores → Configuration → Angeo AEO → CrUX API Key.',
                ['configured' => false]
            );
        }

        $url = $this->urlSampler->getBaseUrl($store);
        $endpoint = self::CRUX_ENDPOINT . '?key=' . urlencode($apiKey);

        $payload = json_encode(['url' => $url, 'formFactor' => 'PHONE']);

        // Send via raw curl call — HttpCache::get only does GET. Reuse the curl
        // via a one-off helper-style call: we POST and read body directly.
        $response = $this->cruxPost($endpoint, (string) $payload);

        if ($response === null) {
            return $this->warn(
                'CrUX API call failed — no data returned.',
                'Verify the API key and that the URL has enough Chrome traffic for CrUX.',
                ['url' => $url]
            );
        }

        $metrics = $response['record']['metrics'] ?? [];
        if (empty($metrics)) {
            return $this->warn(
                'CrUX returned no metrics — insufficient real-user data for this URL.',
                'CrUX needs ~28 days of Chrome traffic. Use Origin level for low-traffic stores.',
                ['url' => $url]
            );
        }

        $report  = [];
        $failing = [];
        foreach (self::THRESHOLDS as $metric => $cfg) {
            if (!isset($metrics[$metric]['percentiles']['p75'])) {
                continue;
            }
            $p75 = (float) $metrics[$metric]['percentiles']['p75'];
            $good = $p75 <= $cfg['good'];
            $report[$metric] = [
                'p75'        => $p75,
                'good_under' => $cfg['good'],
                'unit'       => $cfg['unit'],
                'good'       => $good,
            ];
            if (!$good) {
                $failing[] = sprintf(
                    '%s p75 = %s%s (good ≤ %s%s)',
                    $metric,
                    rtrim(rtrim(sprintf('%.2f', $p75), '0'), '.'),
                    $cfg['unit'],
                    $cfg['good'],
                    $cfg['unit']
                );
            }
        }

        $details = ['url' => $url, 'metrics' => $report];

        if (count($failing) >= 2) {
            return $this->fail(
                sprintf('Core Web Vitals: %d of 3 metrics failing', count($failing)),
                implode(' | ', $failing) . ' — investigate Hyvä migration, image optimisation, FPC, JS deferral.',
                $details
            );
        }

        if (!empty($failing)) {
            return $this->warn(
                sprintf('Core Web Vitals: %d of 3 metric(s) failing', count($failing)),
                implode(' | ', $failing),
                $details
            );
        }

        return $this->pass(
            'Core Web Vitals: all 3 metrics in "good" range (p75).',
            $details
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cruxPost(string $endpoint, string $payload): ?array
    {
        // We need POST + JSON body, which HttpCache doesn't model. Drop down
        // to the underlying Curl via a minimal direct call. The result is not
        // cached (per-request unique payload) — acceptable for an external-API
        // checker.
        try {
            $curl = new \Magento\Framework\HTTP\Client\Curl();
            $curl->setTimeout(15);
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->addHeader('User-Agent', HttpCache::USER_AGENT);
            $curl->post($endpoint, $payload);
            $status = (int) $curl->getStatus();
            $body   = (string) $curl->getBody();
            if ($status !== 200 || $body === '') {
                return null;
            }
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
