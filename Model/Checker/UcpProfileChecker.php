<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates the UCP (Universal Commerce Protocol) profile at /.well-known/ucp.
 *
 * UCP launched January 2026 with Shopify, Wayfair, Target, Etsy and Walmart as
 * launch merchants. It's the discovery layer Google AI Mode / Gemini use to
 * understand what a store can offer. Companion module: angeo/module-ucp.
 *
 * Checks (in order, fail-stop on critical):
 *  - HTTPS-only (UCP spec requires it)
 *  - 200 OK + JSON content-type
 *  - ucp.version present and recognised
 *  - services["dev.ucp.shopping"] declared
 *  - signing_keys present, EC/P-256, NO private fields leaked
 *  - Cache-Control max-age >= 60 per spec
 *  - capabilities advertised correspond to reachable endpoints (warn)
 *
 * @since 3.0.0
 */
class UcpProfileChecker extends AbstractChecker
{
    private const KNOWN_PROTOCOL_VERSIONS = ['2026-04-08'];
    private const PRIVATE_JWK_FIELDS      = ['d', 'p', 'q', 'dp', 'dq', 'qi'];

    /**
     * Candidate locations the profile may be served from. The spec path is
     * /.well-known/ucp; some generators also expose a .json alias.
     */
    private const CANDIDATE_PATHS = ['/.well-known/ucp', '/.well-known/ucp.json'];

    /** Deployment-config path where angeo/module-ucp stores its signing keys. */
    private const DEPLOY_CONFIG_KEYS_PATH = 'ucp/signing_keys';

    /**
     * @param HttpCache $httpCache
     * @param StoreUrlSampler $urlSampler
     * @param DeploymentConfig|null $deploymentConfig Optional — lets the 404
     *        branch distinguish "module not installed" from "keys generated
     *        but endpoint not served". Nullable for backward compatibility.
     */
    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly ?DeploymentConfig $deploymentConfig = null,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'UCP profile — /.well-known/ucp';
    }

    public function getCode(): string
    {
        return 'ucp_profile';
    }

    public function getWeight(): float
    {
        return 0.9;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-ucp';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);

        // UCP requires HTTPS — fail fast if base isn't HTTPS
        if (stripos($base, 'https://') !== 0) {
            return $this->fail(
                'UCP requires HTTPS. Store base URL is not HTTPS.',
                'Configure HTTPS for the store and re-run audit.',
                ['url' => $base]
            );
        }

        // Probe candidate locations. /.well-known/ucp is canonical; a .json
        // alias is accepted as a fallback so a slightly different generator
        // doesn't read as "missing".
        $url     = $base . self::CANDIDATE_PATHS[0];
        $status  = 0;
        $body    = '';
        $headers = [];
        foreach (self::CANDIDATE_PATHS as $path) {
            $candidate = $base . $path;
            [$cStatus, $cBody, $cHeaders] = $this->fetchWithHeaders($candidate);
            if ($cStatus === 200 && $cBody !== '') {
                $url     = $candidate;
                $status  = $cStatus;
                $body    = $cBody;
                $headers = $cHeaders;
                break;
            }
            // Remember the canonical-path status for the diagnostic message.
            if ($path === self::CANDIDATE_PATHS[0]) {
                $status = $cStatus;
            }
        }

        if ($status !== 200 || $body === '') {
            return $this->buildNotServedFailure($url, $status);
        }

        // Content-Type
        $contentType = (string) ($headers['content-type'] ?? '');
        if (stripos($contentType, 'application/json') === false) {
            return $this->fail(
                sprintf('UCP profile returned non-JSON content-type: "%s"', $contentType ?: 'missing'),
                'Ensure the controller emits Content-Type: application/json.',
                ['url' => $url, 'content_type' => $contentType]
            );
        }

        $profile = json_decode($body, true);
        if (!is_array($profile)) {
            return $this->fail(
                'UCP profile body is not valid JSON.',
                'Re-generate via angeo/module-ucp.',
                ['url' => $url]
            );
        }

        $issues   = [];
        $warnings = [];

        $ucp = $profile['ucp'] ?? null;
        if (!is_array($ucp)) {
            $issues[] = 'Missing "ucp" envelope';
            return $this->fail(
                'UCP profile structure invalid.',
                implode(' | ', $issues),
                ['url' => $url, 'body_preview' => mb_substr($body, 0, 200)]
            );
        }

        // Version
        $version = $ucp['version'] ?? null;
        if (!is_string($version) || $version === '') {
            $issues[] = 'Missing ucp.version';
        } elseif (!in_array($version, self::KNOWN_PROTOCOL_VERSIONS, true)) {
            $warnings[] = sprintf(
                'UCP protocol version "%s" not in known set (%s) — may be newer than this audit knows',
                $version,
                implode(', ', self::KNOWN_PROTOCOL_VERSIONS)
            );
        }

        // services.dev.ucp.shopping
        $services = $ucp['services'] ?? [];
        if (!is_array($services) || empty($services['dev.ucp.shopping'])) {
            $issues[] = 'Missing services["dev.ucp.shopping"] — required for any shopping capability';
        } else {
            $shopping = $services['dev.ucp.shopping'];
            if (is_array($shopping) && isset($shopping[0]) && is_array($shopping[0])) {
                $svc = $shopping[0];
                foreach (['version', 'spec', 'transport', 'endpoint', 'schema'] as $required) {
                    if (empty($svc[$required])) {
                        $issues[] = sprintf('services["dev.ucp.shopping"][0].%s missing', $required);
                    }
                }
                // Endpoint must be HTTPS
                if (!empty($svc['endpoint']) && stripos((string) $svc['endpoint'], 'https://') !== 0) {
                    $issues[] = 'Shopping service endpoint is not HTTPS';
                }
            }
        }

        // Signing keys
        $signingKeys = $profile['signing_keys'] ?? [];
        if (!is_array($signingKeys) || empty($signingKeys)) {
            $warnings[] = 'No signing_keys declared — RFC 9421 HTTP Message Signatures will not validate';
        } else {
            foreach ($signingKeys as $i => $key) {
                if (!is_array($key)) {
                    continue;
                }
                // Detect leaked private fields — CRITICAL SECURITY ISSUE
                foreach (self::PRIVATE_JWK_FIELDS as $priv) {
                    if (array_key_exists($priv, $key)) {
                        $issues[] = sprintf(
                            'SECURITY: signing_keys[%d] leaks private JWK field "%s" — rotate keys NOW',
                            $i,
                            $priv
                        );
                    }
                }
                // P-256 / ES256 sanity
                if (($key['kty'] ?? null) !== 'EC') {
                    $warnings[] = sprintf('signing_keys[%d].kty is not "EC"', $i);
                }
                if (($key['crv'] ?? null) !== 'P-256') {
                    $warnings[] = sprintf('signing_keys[%d].crv is not "P-256"', $i);
                }
                if (($key['alg'] ?? null) !== 'ES256') {
                    $warnings[] = sprintf('signing_keys[%d].alg is not "ES256"', $i);
                }
                if (empty($key['kid'])) {
                    $warnings[] = sprintf('signing_keys[%d] missing kid', $i);
                }
            }
        }

        // Cache-Control
        $cacheControl = (string) ($headers['cache-control'] ?? '');
        $maxAge = null;
        if (preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $m)) {
            $maxAge = (int) $m[1];
        }
        if ($maxAge === null) {
            $warnings[] = 'No max-age in Cache-Control — UCP spec requires max-age >= 60';
        } elseif ($maxAge < 60) {
            $warnings[] = sprintf('Cache-Control max-age=%d — UCP spec requires >= 60', $maxAge);
        }

        $details = [
            'url'              => $url,
            'protocol_version' => $version,
            'signing_keys'     => count($signingKeys),
            'cache_max_age'    => $maxAge,
            'capabilities'     => array_keys($ucp['capabilities'] ?? []),
        ];

        if (!empty($issues)) {
            return $this->fail(
                sprintf('UCP profile has %d critical issue(s): %s', count($issues), $issues[0]),
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('UCP profile valid — %d improvement(s)', count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('UCP profile valid — protocol %s, %d signing key(s), max-age %ds.',
                $version,
                count($signingKeys),
                $maxAge ?? 0
            ),
            $details
        );
    }

    /**
     * Build the failure for a non-200 profile, distinguishing two very
     * different root causes:
     *
     *  (a) signing keys ARE configured in app/etc/env.php (ucp/signing_keys)
     *      but the endpoint still 404s → the keys exist, but nothing is
     *      *serving* the profile. The module may be disabled, the route not
     *      registered, or — very commonly — the web server is blocking
     *      dotfile paths (a `location ~ /\. { deny all; }` rule swallows the
     *      whole /.well-known/ tree). Generating keys again will NOT help.
     *
     *  (b) no keys configured → the module is genuinely not set up yet.
     */
    private function buildNotServedFailure(string $url, int $status): CheckResult
    {
        $httpLabel    = $status ?: 'error';
        $keysPresent  = $this->signingKeysConfigured();

        if ($keysPresent) {
            return $this->fail(
                sprintf('UCP signing keys are configured but the profile is not served (HTTP %s).', $httpLabel),
                'Keys exist in app/etc/env.php, so do NOT regenerate them. The endpoint itself is not '
                . 'reachable: (1) confirm the module is enabled — bin/magento module:status Angeo_Ucp; '
                . '(2) check that your web server is not blocking dotfile paths — a '
                . '"location ~ /\\. { deny all; }" rule in nginx blocks the entire /.well-known/ tree, so '
                . 'add an explicit "location ^~ /.well-known/ { ... }" above it; '
                . '(3) clear cache and re-deploy routes: bin/magento cache:flush.',
                ['url' => $url, 'http_status' => $status, 'signing_keys_configured' => true]
            );
        }

        return $this->fail(
            sprintf('UCP profile not found (HTTP %s).', $httpLabel),
            'Install angeo/module-ucp and generate signing keys: bin/magento angeo:ucp:keys:generate',
            ['url' => $url, 'http_status' => $status, 'signing_keys_configured' => false]
        );
    }

    /**
     * Whether UCP signing keys are present in deployment config (env.php).
     * Read-only and value-blind — only presence/count is used, never the key
     * material itself.
     */
    private function signingKeysConfigured(): bool
    {
        if ($this->deploymentConfig === null) {
            return false;
        }
        try {
            $keys = $this->deploymentConfig->get(self::DEPLOY_CONFIG_KEYS_PATH);
        } catch (\Throwable) {
            return false;
        }
        return is_array($keys) && $keys !== [];
    }
}
