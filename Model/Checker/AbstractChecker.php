<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Magento\Framework\HTTP\Client\Curl;

abstract class AbstractChecker implements CheckerInterface
{
    protected const DEFAULT_TIMEOUT = 10;

    public function __construct(
        protected readonly Curl $curl
    ) {}

    /**
     * Fetch a URL and return [statusCode, body].
     * Returns [0, ''] on connection failure.
     */
    protected function fetch(string $url): array
    {
        try {
            $this->curl->setTimeout(self::DEFAULT_TIMEOUT);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 5);
            $this->curl->addHeader('User-Agent', 'AngeoAeoAudit/1.0 (+https://angeo.dev)');
            $this->curl->get($url);

            return [
                (int) $this->curl->getStatus(),
                (string) $this->curl->getBody(),
            ];
        } catch (\Exception) {
            return [0, ''];
        }
    }

    /**
     * Check whether a URL returns HTTP 200.
     */
    protected function urlExists(string $url): bool
    {
        [$status] = $this->fetch($url);
        return $status === 200;
    }

    /**
     * Trim trailing slash and return a clean base URL.
     */
    protected function normalizeBase(string $baseUrl): string
    {
        return rtrim($baseUrl, '/');
    }
}
