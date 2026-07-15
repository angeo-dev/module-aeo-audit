<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
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
        private readonly EncryptorInterface $encryptor,
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
        $stored = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $store->getCode()
        );

        // The key is stored encrypted. A value that cannot be decrypted (e.g.
        // rotated crypt key, corrupted data) is treated exactly like "no key":
        // we never send a garbage credential to the external API.
        try {
            $apiKey = $stored === '' ? '' : (string) $this->encryptor->decrypt($stored);
        } catch (\Throwable) {
            $apiKey = '';
        }

        if ($apiKey === '') {
            return $this->warn(
                'CrUX API key not configured — Core Web Vitals not measured.',
                'Get a free key at console.cloud.google.com and set under '
                    . 'Stores → Configuration → Angeo AEO → CrUX API Key.',
                ['configured' => false]
            );
        }

        $url = $this->urlSampler->getBaseUrl($store);
        $payload = (string) json_encode(['url' => $url, 'formFactor' => 'PHONE']);

        // POST through HttpCache, which centralises TLS verification and SSRF
        // protection. The API key goes in the X-Goog-Api-Key header — never in
        // the URL, where it would leak into access logs, proxies and history.
        [$httpStatus, $body] = $this->httpCache->post(
            self::CRUX_ENDPOINT,
            $payload,
            ['X-Goog-Api-Key' => $apiKey]
        );

        $response = null;
        if ($httpStatus === 200 && $body !== '') {
            $decoded = json_decode($body, true);
            $response = is_array($decoded) ? $decoded : null;
        }

        // CrUX returns 404 when it simply has no field data for this URL — i.e.
        // the site doesn't get enough Chrome traffic to clear Google's privacy
        // threshold. That is NOT a failure: the key works and the call
        // succeeded. Penalising it would unfairly mark every low-traffic / demo
        // / brand-new store down for something entirely outside their control.
        // So we treat 404 as a neutral, non-scoring INFO result (weight 0, so it
        // affects neither the numerator nor the denominator of the score).
        if ($response === null && $httpStatus === 404) {
            return $this->infoNoData($url);
        }

        if ($response === null) {
            return $this->warn(
                'CrUX API call failed — no data returned.',
                'Verify the API key and that the URL has enough Chrome traffic for CrUX.',
                ['url' => $url, 'http_status' => $httpStatus]
            );
        }

        $metrics = $response['record']['metrics'] ?? [];
        if (empty($metrics)) {
            // 200 but no metrics is the same situation as a 404: no field data.
            return $this->infoNoData($url);
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
     * Neutral, non-scoring result for "CrUX has no field data for this URL".
     *
     * Implemented as a PASS with weight 0 so it contributes nothing to either
     * side of the weighted score — the signal is simply not measurable here, so
     * it neither helps nor hurts. The message makes the informational nature
     * explicit. (The audit has only pass/warn/fail statuses; a zero-weight pass
     * is the cleanest way to express "informational, excluded from scoring"
     * without touching the report model.)
     */
    private function infoNoData(string $url): CheckResult
    {
        return CheckResult::pass(
            $this->getName(),
            'Core Web Vitals not scored — CrUX has no field data for this URL '
                . '(insufficient Chrome traffic). This does not affect the score.',
            ['url' => $url, 'crux_data_available' => false, 'excluded_from_score' => true],
            $this->getCode(),
            0.0, // weight 0 → excluded from numerator and denominator
            $this->getCategory(),
            CheckerInterface::SEVERITY_INFORMATIONAL
        );
    }

    /**
     * @param int|null $httpStatus Receives the HTTP status (by reference) so the
     *                             caller can distinguish 404 "no data" from real
     *                             failures.
     * @return array<string, mixed>|null
     */
}