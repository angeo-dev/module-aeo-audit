<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Base class for all AEO checkers.
 *
 * Provides:
 *  - Shared HTTP fetch through HttpCache (request-scoped memoisation)
 *  - URL sampling through StoreUrlSampler
 *  - JSON-LD extraction with recursive @graph flattening
 *  - PASS / WARN / FAIL factories that auto-attach checker metadata
 *  - Default category/severity (override in subclass)
 *
 * Subclasses MUST override getName(), getCode(), getWeight(), and check().
 * They MAY override getCategory(), getSeverity(), getFixCommand().
 *
 * @since 3.0.0 — split from v2 AbstractChecker; signature changes are BC-break
 *                (check now takes StoreInterface), hence major version bump.
 */
abstract class AbstractChecker implements CheckerInterface
{
    public function __construct(
        protected readonly HttpCache       $httpCache,
        protected readonly StoreUrlSampler $urlSampler,
    ) {
    }

    /**
     * Default — most checkers are pure technical signals. Override for
     * external-API or live-log checkers.
     */
    public function getCategory(): string
    {
        return CheckerInterface::CATEGORY_TECHNICAL;
    }

    /**
     * Default severity tracks the weight: weight >= 0.8 → critical,
     * 0.6–0.79 → important, < 0.6 → informational. Override for exceptions.
     */
    public function getSeverity(): string
    {
        return match (true) {
            $this->getWeight() >= 0.8 => CheckerInterface::SEVERITY_CRITICAL,
            $this->getWeight() >= 0.6 => CheckerInterface::SEVERITY_IMPORTANT,
            default                   => CheckerInterface::SEVERITY_INFORMATIONAL,
        };
    }

    /**
     * Default: no fix command. Override in checkers that have a fix module.
     */
    public function getFixCommand(): string
    {
        return '';
    }

    abstract public function check(StoreInterface $store): CheckResult;

    // ── HTTP helpers ─────────────────────────────────────────────────

    /**
     * Fetch a URL and return [statusCode, body].
     *
     * @return array{0: int, 1: string}
     */
    protected function fetch(string $url, int $timeout = HttpCache::DEFAULT_TIMEOUT): array
    {
        [$status, $body] = $this->httpCache->get($url, $timeout);
        return [$status, $body];
    }

    /**
     * Fetch a URL and return [statusCode, body, headers].
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    protected function fetchWithHeaders(string $url, int $timeout = HttpCache::DEFAULT_TIMEOUT): array
    {
        return $this->httpCache->get($url, $timeout);
    }

    protected function urlExists(string $url): bool
    {
        return $this->httpCache->status($url) === 200;
    }

    protected function statusCode(string $url): int
    {
        return $this->httpCache->status($url);
    }

    protected function normalizeBase(string $baseUrl): string
    {
        return rtrim($baseUrl, '/');
    }

    // ── Result factories ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $details
     */
    protected function pass(string $message, array $details = []): CheckResult
    {
        return CheckResult::pass($this->getName(), $message, $details, $this->getCode(), $this->getWeight());
    }

    /**
     * @param array<string, mixed> $details
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
     * @param array<string, mixed> $details
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

    // ── JSON-LD helpers ──────────────────────────────────────────────

    /**
     * Parse JSON-LD blocks from HTML and return all schema objects.
     * Recursively flattens @graph at any nesting level and handles array-typed
     * JSON-LD roots.
     *
     * @return list<array<string, mixed>>
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
     *
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $schemas
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
     * Find first schema node whose @type matches $type. The @type field can be
     * a string OR an array (Schema.org permits multi-typing) — both are matched.
     *
     * @param list<array<string, mixed>> $schemas
     * @return array<string, mixed>|null
     */
    protected function findSchemaByType(array $schemas, string $type): ?array
    {
        foreach ($schemas as $schema) {
            $schemaType = $schema['@type'] ?? null;
            if (is_string($schemaType) && $schemaType === $type) {
                return $schema;
            }
            if (is_array($schemaType) && in_array($type, $schemaType, true)) {
                return $schema;
            }
        }
        return null;
    }
}
