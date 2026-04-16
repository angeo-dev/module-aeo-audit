<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Validates /llms.txt per llmstxt.org spec.
 *
 * Improvements over v1:
 * - Spec-compliant: requires H1 title (mandatory per spec)
 * - Validates markdown links exist (file without links is useless)
 * - Counts sections and links for quality score
 * - Flags oversized files (>512KB)
 * - Checks /llms-full.txt as bonus
 * - Removes dependency on hardcoded section names ("## Products" etc.)
 *   because those are store-specific, not spec requirements
 */
class LlmsTxtChecker extends AbstractChecker
{
    private const MIN_CONTENT_LENGTH = 100;
    private const MAX_BYTES          = 524288; // 512 KB

    public function getName(): string  { return 'llms.txt — AI content map'; }
    public function getCode(): string  { return 'llms_txt'; }
    public function getWeight(): float { return 1.0; }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/llms.txt');

        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'llms.txt not found (HTTP ' . ($status ?: 'error') . ').',
                'Install angeo/module-llms-txt and run: bin/magento angeo:llms:generate',
                ['url' => $base . '/llms.txt']
            );
        }

        $issues  = [];
        $size    = strlen($body);

        // 1. Must start with H1 (spec requirement)
        if (!preg_match('/^#\s+\S+/m', $body)) {
            $issues[] = 'Missing H1 title — required by llmstxt.org spec (first line must be "# Store Name")';
        }

        // 2. Must contain markdown links
        preg_match_all('/\[.+?\]\(https?:\/\/.+?\)/', $body, $linkMatches);
        $linkCount = count($linkMatches[0]);
        if ($linkCount === 0) {
            $issues[] = 'No markdown links found — llms.txt without links gives AI crawlers no navigation targets';
        }

        // 3. File size sanity
        if ($size < self::MIN_CONTENT_LENGTH) {
            $issues[] = sprintf('File is very small (%d bytes) — looks like a stub', $size);
        }
        if ($size > self::MAX_BYTES) {
            $issues[] = sprintf('File is %.1f KB — split into llms.txt + llms-full.txt per spec', $size / 1024);
        }

        // Count sections (H2 blocks)
        preg_match_all('/^##\s+/m', $body, $sectionMatches);
        $sectionCount = count($sectionMatches[0]);

        // Check llms-full.txt
        [$fullStatus] = $this->fetch($base . '/llms-full.txt');
        $hasFullTxt   = ($fullStatus === 200);

        $details = [
            'url'          => $base . '/llms.txt',
            'size_bytes'   => $size,
            'sections'     => $sectionCount,
            'links'        => $linkCount,
            'llms_full_txt' => $hasFullTxt,
        ];

        if (!empty($issues)) {
            return $this->warn(
                sprintf('llms.txt found but has %d issue(s): %s', count($issues), $issues[0]),
                implode(' | ', $issues),
                $details
            );
        }

        return $this->pass(
            sprintf(
                'llms.txt valid — %d section(s), %d link(s)%s.',
                $sectionCount,
                $linkCount,
                $hasFullTxt ? ', llms-full.txt present' : ''
            ),
            $details
        );
    }
}
