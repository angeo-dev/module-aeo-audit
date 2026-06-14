<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Request-scoped HTTP cache for AEO audit checkers.
 *
 * Multiple checkers fetch the same resources (robots.txt, sitemap, homepage HTML).
 * Without caching, a single `runAll()` against 10 stores can issue hundreds of
 * redundant HTTP requests. This class memoises responses for the lifetime of
 * the audit run.
 *
 * Cache key = method + URL. Headers are also captured.
 *
 * Security posture (since 3.1.0):
 *  - TLS peer/host verification is ALWAYS enabled.
 *  - Only HTTP/HTTPS protocols are permitted, including on redirects
 *    (mitigates SSRF pivots to file://, gopher://, ftp:// etc.).
 *  - A fresh Curl client is created per request via CurlFactory so headers
 *    and options never bleed between requests.
 *
 * NOT thread-safe — Magento's request model is single-threaded per execution.
 *
 * @api
 * @since 3.0.0
 */
class HttpCache
{
    public const DEFAULT_TIMEOUT   = 10;
    public const DEFAULT_REDIRECTS = 3;
    public const USER_AGENT        = 'AngeoAeoAudit/3.1 (+https://angeo.dev)';

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> */
    private array $cache = [];

    /** @var array<string, int> */
    private array $stats = ['hits' => 0, 'misses' => 0];

    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    /**
     * Fetch URL with caching. Returns [status, body, headers].
     *
     * Returns [0, '', []] on any connection failure — callers must handle gracefully.
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    public function get(string $url, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $key = 'GET:' . $url;
        if (isset($this->cache[$key])) {
            $this->stats['hits']++;
            return $this->cache[$key];
        }
        $this->stats['misses']++;
        $this->cache[$key] = $this->doRequest('GET', $url, $timeout);
        return $this->cache[$key];
    }

    /**
     * POST a payload. NOT cached — POST requests are assumed non-idempotent
     * and payload-specific (e.g. external API queries).
     *
     * @param array<string, string> $headers Extra request headers (name => value)
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    public function post(
        string $url,
        string $payload,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT
    ): array {
        return $this->doRequest('POST', $url, $timeout, $payload, $headers);
    }

    /**
     * HEAD-like check — returns just the status code.
     *
     * Internally uses GET (Magento's Curl client has no native HEAD support),
     * but result is cached so subsequent get() calls don't re-fetch.
     */
    public function status(string $url, int $timeout = self::DEFAULT_TIMEOUT): int
    {
        return $this->get($url, $timeout)[0];
    }

    /**
     * Reset cache — call between audit runs if reusing the service instance.
     */
    public function reset(): void
    {
        $this->cache = [];
        $this->stats = ['hits' => 0, 'misses' => 0];
    }

    /**
     * @return array{hits: int, misses: int}
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function doRequest(
        string $method,
        string $url,
        int $timeout,
        string $payload = '',
        array $extraHeaders = []
    ): array {
        try {
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $curl->setOption(CURLOPT_MAXREDIRS, self::DEFAULT_REDIRECTS);
            // SSRF hardening: never follow redirects into non-web protocols.
            $curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            $curl->setOption(CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            // TLS verification stays ON (cURL default) — do not disable it here.
            $curl->addHeader('User-Agent', self::USER_AGENT);
            foreach ($extraHeaders as $name => $value) {
                $curl->addHeader($name, $value);
            }

            if ($method === 'POST') {
                $curl->post($url, $payload);
            } else {
                $curl->get($url);
            }

            $status = (int) $curl->getStatus();
            $body   = (string) $curl->getBody();

            $rawHeaders = method_exists($curl, 'getHeaders')
                ? (array) $curl->getHeaders()
                : [];

            $headers = [];
            foreach ($rawHeaders as $k => $v) {
                $headers[strtolower((string) $k)] = is_array($v) ? (string) reset($v) : (string) $v;
            }

            return [$status, $body, $headers];
        } catch (\Throwable) {
            return [0, '', []];
        }
    }
}
