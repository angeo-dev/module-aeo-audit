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

    public function __construct(protected readonly Curl $curl) {}

    /**
     * Fetch a URL and return [statusCode, body].
     * Returns [0, ''] on any connection failure — callers must handle gracefully.
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

    protected function urlExists(string $url): bool
    {
        [$status] = $this->fetch($url);
        return $status === 200;
    }

    protected function statusCode(string $url): int
    {
        [$status] = $this->fetch($url);
        return $status;
    }

    protected function normalizeBase(string $baseUrl): string
    {
        return rtrim($baseUrl, '/');
    }

    // ── Convenience result builders that auto-inject code and weight ──────────

    protected function pass(string $message, array $details = []): CheckResult
    {
        return CheckResult::pass($this->getName(), $message, $details, $this->getCode(), $this->getWeight());
    }

    protected function warn(string $message, string $recommendation = '', array $details = []): CheckResult
    {
        return CheckResult::warn($this->getName(), $message, $recommendation, $details, $this->getCode(), $this->getWeight(), $this->getFixCommand());
    }

    protected function fail(string $message, string $recommendation = '', array $details = []): CheckResult
    {
        return CheckResult::fail($this->getName(), $message, $recommendation, $details, $this->getCode(), $this->getWeight(), $this->getFixCommand());
    }

    /**
     * Parse JSON-LD blocks from HTML and return all schema objects (handles @graph).
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
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $node) {
                    $schemas[] = $node;
                }
            } else {
                $schemas[] = $decoded;
            }
        }
        return $schemas;
    }

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
