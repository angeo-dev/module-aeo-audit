<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Framework\HTTP\Client\Curl;

abstract class AbstractChecker implements CheckerInterface
{
    protected const DEFAULT_TIMEOUT = 10;
    protected const USER_AGENT      = 'AngeoAeoAudit/2.0 (+https://angeo.dev)';

    /**
     * @param Curl $curl
     */
    public function __construct(protected readonly Curl $curl)
    {
    }

    /**
     * Fetch a URL and return [statusCode, body].
     *
     * Returns [0, ''] on any connection failure — callers must handle gracefully.
     *
     * @param string $url
     * @return array{0: int, 1: string}
     */
    protected function fetch(string $url): array
    {
        try {
            $this->curl->setTimeout(self::DEFAULT_TIMEOUT);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 3);
            $this->curl->addHeader('User-Agent', self::USER_AGENT);
            $this->curl->get($url);

            return [(int) $this->curl->getStatus(), (string) $this->curl->getBody()];
        } catch (\Exception) {
            return [0, ''];
        }
    }

    /**
     * @param string $url
     * @return bool
     */
    protected function urlExists(string $url): bool
    {
        [$status] = $this->fetch($url);
        return $status === 200;
    }

    /**
     * @param string $url
     * @return int
     */
    protected function statusCode(string $url): int
    {
        [$status] = $this->fetch($url);
        return $status;
    }

    /**
     * @param string $baseUrl
     * @return string
     */
    protected function normalizeBase(string $baseUrl): string
    {
        return rtrim($baseUrl, '/');
    }

    /**
     * @param string $message
     * @param array $details
     * @return CheckResult
     */
    protected function pass(string $message, array $details = []): CheckResult
    {
        return CheckResult::pass($this->getName(), $message, $details, $this->getCode(), $this->getWeight());
    }

    /**
     * @param string $message
     * @param string $recommendation
     * @param array $details
     * @return CheckResult
     */
    protected function warn(string $message, string $recommendation = '', array $details = []): CheckResult
    {
        return CheckResult::warn(
            $this->getName(),
            $message,
            $recommendation,
            $details,
            $this->getCode(),
            $this->getWeight(),
            $this->getFixCommand()
        );
    }

    /**
     * @param string $message
     * @param string $recommendation
     * @param array $details
     * @return CheckResult
     */
    protected function fail(string $message, string $recommendation = '', array $details = []): CheckResult
    {
        return CheckResult::fail(
            $this->getName(),
            $message,
            $recommendation,
            $details,
            $this->getCode(),
            $this->getWeight(),
            $this->getFixCommand()
        );
    }

    /**
     * Parse JSON-LD blocks from HTML and return all schema objects.
     * Recursively flattens @graph at any nesting level and handles array-typed JSON-LD roots.
     *
     * @param string $html
     * @return array
     */
    protected function extractJsonLdSchemas(string $html): array
    {
        $schemas = [];
        preg_match_all(
            '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
            $html,
            $matches
        );
        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!is_array($decoded)) {
                continue;
            }
            $this->collectSchemas($decoded, $schemas);
        }
        return $schemas;
    }

    /**
     * Recursively walk a decoded JSON-LD payload and collect every schema node.
     * Handles: top-level objects, top-level arrays, @graph at any depth.
     */
    private function collectSchemas(array $node, array &$schemas): void
    {
        // Top-level array of schemas
        if (!isset($node['@type']) && !isset($node['@graph']) && array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    $this->collectSchemas($item, $schemas);
                }
            }
            return;
        }

        // Object with @graph — recurse into graph items
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $item) {
                if (is_array($item)) {
                    $this->collectSchemas($item, $schemas);
                }
            }
            // Also keep the parent if it has its own @type
            if (isset($node['@type'])) {
                $schemas[] = $node;
            }
            return;
        }

        // Plain schema node
        $schemas[] = $node;
    }

    /**
     * @param array $schemas
     * @param string $type
     * @return array|null
     */
    protected function findSchemaByType(array $schemas, string $type): ?array
    {
        foreach ($schemas as $schema) {
            if (($schema['@type'] ?? '') === $type) {
                return $schema;
            }
        }
        return null;
    }

    /**
     * Default: no fix command. Override in checkers that have a fix module.
     */
    public function getFixCommand(): string
    {
        return '';
    }
}
