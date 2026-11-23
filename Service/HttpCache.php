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
 *  - Since 4.6.0 redirects are followed here, one hop at a time, not by
 *    cURL. A redirect that stays on the host the audit asked for is always
 *    followed (a store may live on a private network). A redirect to any
 *    other host is followed only when that host resolves to public
 *    addresses, so an open redirect on the store cannot point the audit at
 *    another machine inside the network.
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
    public const USER_AGENT        = 'AngeoAeoAudit/4.6 (+https://angeo.dev)';

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> */
    private array $cache = [];

    /** @var array<string, int> */
    private array $stats = ['hits' => 0, 'misses' => 0];

    private readonly PublicAddress $publicAddress;

    public function __construct(private readonly CurlFactory $curlFactory, ?PublicAddress $publicAddress = null)
    {
        $this->publicAddress = $publicAddress ?? new PublicAddress();
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
     * Fetch URL presenting a custom User-Agent. Cached separately per UA —
     * the whole point (WAF reality probing) is that the same URL may answer
     * differently to different agents, so the cache key includes the UA.
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     * @since 4.0.0
     */
    public function getAs(string $url, string $userAgent, int $timeout = self::DEFAULT_TIMEOUT): array
    {
        $key = 'GET:' . $userAgent . ':' . $url;
        if (isset($this->cache[$key])) {
            $this->stats['hits']++;
            return $this->cache[$key];
        }
        $this->stats['misses']++;
        $this->cache[$key] = $this->doRequest('GET', $url, $timeout, '', [], $userAgent);
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
     * Drop one cached GET, so the next get() / getAs() goes to the network.
     * Pass the same User-Agent that was given to getAs(); '' means get().
     *
     * Used for a single retry after HTTP 429 / 503, which are answers about
     * that moment rather than about the URL.
     *
     * @since 4.6.0
     */
    public function forget(string $url, string $userAgent = ''): void
    {
        unset($this->cache[$userAgent === '' ? 'GET:' . $url : 'GET:' . $userAgent . ':' . $url]);
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
        array $extraHeaders = [],
        string $userAgentOverride = ''
    ): array {
        try {
            $origin = strtolower((string) parse_url($url, PHP_URL_HOST));
            $hops   = 0;

            while (true) {
                /** @var Curl $curl */
                $curl = $this->curlFactory->create();
                $curl->setTimeout($timeout);
                // Redirects are followed below, where each target is checked.
                $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
                // SSRF hardening: web protocols only.
                $curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                $curl->setOption(CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                // TLS verification stays ON (cURL default) — do not disable it here.
                $curl->addHeader('User-Agent', $userAgentOverride !== '' ? $userAgentOverride : self::USER_AGENT);
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
                $rawHeaders = (array) $curl->getHeaders();

                $headers = [];
                foreach ($rawHeaders as $k => $v) {
                    $headers[strtolower((string) $k)] = is_array($v) ? (string) reset($v) : (string) $v;
                }

                $location = $headers['location'] ?? '';
                if (!in_array($status, [301, 302, 303, 307, 308], true) || $location === '') {
                    return [$status, $body, $headers];
                }
                if (++$hops > self::DEFAULT_REDIRECTS) {
                    return [0, '', []];
                }
                $next = self::resolveRedirect($url, $location);
                if ($next === null) {
                    return [0, '', []];
                }
                $nextHost = strtolower((string) parse_url($next, PHP_URL_HOST));
                if ($nextHost !== $origin && !$this->publicAddress->hostIsPublic($nextHost)) {
                    // Off-site redirect into non-public address space.
                    return [0, '', []];
                }
                // As browsers and cURL do: the follow-up of a redirect is a GET,
                // except for 307/308, which keep the method.
                if (!in_array($status, [307, 308], true)) {
                    $method  = 'GET';
                    $payload = '';
                }
                $url = $next;
            }
        } catch (\Throwable) {
            return [0, '', []];
        }
    }

    /**
     * Absolute http(s) URL for a Location header, or null when it is not one.
     */
    public static function resolveRedirect(string $base, string $location): ?string
    {
        $location = trim($location);
        $b = parse_url($base);
        if ($location === '' || !is_array($b) || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location) === 1) {
            $url = $location;
        } elseif (str_starts_with($location, '//')) {
            $url = $b['scheme'] . ':' . $location;
        } elseif (str_starts_with($location, '/')) {
            $url = $root . $location;
        } else {
            $path = (string) ($b['path'] ?? '/');
            $url  = $root . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
        }

        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])
            || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)
        ) {
            return null;
        }

        return $url;
    }
}
