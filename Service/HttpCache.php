<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

use Magento\Framework\HTTP\Client\Curl;

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
 * NOT thread-safe — Magento's request model is single-threaded per execution.
 *
 * @api
 * @since 3.0.0
 */
class HttpCache
{
    public const DEFAULT_TIMEOUT  = 10;
    public const DEFAULT_REDIRECTS = 3;
    public const USER_AGENT       = 'AngeoAeoAudit/3.0 (+https://angeo.dev)';

    /** @var array<string, array{0: int, 1: string, 2: array<string, string>}> */
    private array $cache = [];

    /** @var array<string, int> */
    private array $stats = ['hits' => 0, 'misses' => 0];

    public function __construct(private readonly Curl $curl)
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
        $this->cache[$key] = $this->doFetch($url, $timeout);
        return $this->cache[$key];
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
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function doFetch(string $url, int $timeout): array
    {
        try {
            $this->curl->setTimeout($timeout);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, self::DEFAULT_REDIRECTS);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            $this->curl->addHeader('User-Agent', self::USER_AGENT);
            $this->curl->get($url);

            $status = (int) $this->curl->getStatus();
            $body   = (string) $this->curl->getBody();

            $rawHeaders = method_exists($this->curl, 'getHeaders')
                ? (array) $this->curl->getHeaders()
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
