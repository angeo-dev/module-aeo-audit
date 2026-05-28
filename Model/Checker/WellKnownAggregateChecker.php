<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Inventories AI / commerce well-known endpoints.
 *
 * The 2026 AEO / agentic-commerce stack uses several /.well-known/ resources.
 * This checker maps which are present and which still need installing. It's
 * lightweight (HEAD-equivalents only) and informational — score impact is low,
 * but the detail block is a useful onboarding map for operators.
 *
 * Detected:
 *  - /.well-known/ucp           → angeo/module-ucp
 *  - /.well-known/ai-plugin.json → OpenAI ChatGPT merchant signal
 *  - /.well-known/security.txt  → RFC 9116 — agentic-trust signal
 *  - /.well-known/mcp           → MCP server (emerging)
 *
 * @since 3.0.0
 */
class WellKnownAggregateChecker extends AbstractChecker
{
    /** @var array<string, array{path: string, label: string, critical: bool, fix: string}> */
    private const KNOWN_ENDPOINTS = [
        'ucp' => [
            'path'     => '/.well-known/ucp',
            'label'    => 'UCP profile (Universal Commerce Protocol)',
            'critical' => false,
            'fix'      => 'composer require angeo/module-ucp',
        ],
        'ai_plugin' => [
            'path'     => '/.well-known/ai-plugin.json',
            'label'    => 'OpenAI ai-plugin.json (ChatGPT merchant)',
            'critical' => false,
            'fix'      => 'composer require angeo/module-openai-product-feed',
        ],
        'security_txt' => [
            'path'     => '/.well-known/security.txt',
            'label'    => 'security.txt (RFC 9116)',
            'critical' => false,
            'fix'      => 'Create security.txt manually per RFC 9116',
        ],
        'mcp' => [
            'path'     => '/.well-known/mcp',
            'label'    => 'MCP server discovery',
            'critical' => false,
            'fix'      => 'No published Magento module yet — emerging standard',
        ],
    ];

    public function getName(): string
    {
        return 'Well-known endpoints — AEO discovery matrix';
    }

    public function getCode(): string
    {
        return 'well_known';
    }

    public function getWeight(): float
    {
        return 0.5;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);

        $matrix       = [];
        $foundCount   = 0;
        $totalCount   = count(self::KNOWN_ENDPOINTS);
        $missingFixes = [];

        foreach (self::KNOWN_ENDPOINTS as $key => $endpoint) {
            $status   = $this->statusCode($base . $endpoint['path']);
            $present  = ($status === 200);
            $matrix[$key] = [
                'path'        => $endpoint['path'],
                'label'       => $endpoint['label'],
                'http_status' => $status,
                'present'     => $present,
            ];
            if ($present) {
                $foundCount++;
            } else {
                $missingFixes[] = sprintf('%s — %s', $endpoint['label'], $endpoint['fix']);
            }
        }

        $details = [
            'base_url'    => $base,
            'matrix'      => $matrix,
            'found_count' => $foundCount,
            'total_count' => $totalCount,
        ];

        // None found = WARN (informational this is, not a hard fail)
        if ($foundCount === 0) {
            return $this->warn(
                sprintf('No well-known AEO endpoints found (0/%d)', $totalCount),
                implode(' | ', $missingFixes),
                $details
            );
        }

        if ($foundCount < $totalCount) {
            return $this->warn(
                sprintf('Well-known endpoints partial: %d/%d present', $foundCount, $totalCount),
                implode(' | ', $missingFixes),
                $details
            );
        }

        return $this->pass(
            sprintf('All %d well-known AEO endpoints present.', $totalCount),
            $details
        );
    }
}
