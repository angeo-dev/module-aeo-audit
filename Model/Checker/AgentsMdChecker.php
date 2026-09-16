<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates /agents.md — the operator's manual a store publishes for AI agents.
 *
 * Distinct from the A2A Agent Card at /.well-known/agent-card.json, which
 * {@see AgentCardChecker} covers: the card is a machine-readable capability
 * descriptor for agent-to-agent transport, agents.md is prose an LLM reads to
 * learn how this shop expects to be dealt with. Shopify rolled the file out
 * across its stores and then made it the canonical file, with llms.txt
 * pointing at it, which is what moved it from convention to expectation.
 *
 * What actually matters in the file is not its length but whether an agent
 * about to recommend or transact can find the terms it must quote: delivery,
 * returns, privacy. A file that describes the shop warmly and links no
 * policies leaves the agent to summarise from memory, which is how wrong
 * refund windows end up in front of shoppers.
 *
 * @since 4.2.0
 */
class AgentsMdChecker extends AbstractChecker
{
    public const PATH = '/agents.md';

    private const AGENTIC_SITEMAP = '/sitemap_agentic_discovery.xml';
    private const MIN_BYTES       = 120;

    /** Policy topics an agent needs before it can answer a buying question. */
    private const POLICY_KEYWORDS = [
        'delivery' => ['delivery', 'shipping'],
        'returns'  => ['return', 'refund'],
        'privacy'  => ['privacy'],
    ];

    public function getName(): string
    {
        return 'agents.md — instructions for AI agents';
    }

    public function getCode(): string
    {
        return 'agents_md';
    }

    public function getWeight(): float
    {
        return 0.7;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->normalizeBase($this->urlSampler->getBaseUrl($store));
        $url  = $base . self::PATH;

        [$status, $body, $headers] = $this->fetchWithHeaders($url);

        if ($status !== 200 || trim($body) === '') {
            return $this->fail(
                sprintf('agents.md not found (HTTP %s).', $status ?: 'error'),
                'Publish /agents.md with your delivery, returns and privacy terms so agents quote '
                . 'your policy instead of guessing. With angeo/module-llms-txt: enable Formats → '
                . 'Generate agents.md, fill in Agents.md Content, then bin/magento angeo:llms:generate.',
                ['url' => $url, 'status' => $status]
            );
        }

        // A markdown file served as text/html usually means a CMS page is
        // answering, not a real file — agents parse it as a web page.
        $contentType = strtolower($headers['content-type'] ?? '');
        $issues      = [];
        $warnings    = [];
        $size        = strlen($body);
        $bodyLower   = strtolower($body);

        if ($size < self::MIN_BYTES) {
            $issues[] = sprintf('File is only %d bytes — looks like a placeholder', $size);
        }

        if ($contentType !== '' && !str_contains($contentType, 'markdown') && !str_contains($contentType, 'text/plain')) {
            $warnings[] = sprintf(
                'Served as "%s" rather than text/markdown — check a CMS page is not shadowing the file',
                $contentType
            );
        }

        if (!str_starts_with(ltrim($body, "\xEF\xBB\xBF \n\r\t"), '#')) {
            $warnings[] = 'Does not open with a heading — start with "# Store Name" so the file reads as a document';
        }

        // ── Policies: the part with practical consequences ──────────────
        $missingPolicies = [];
        foreach (self::POLICY_KEYWORDS as $topic => $keywords) {
            $found = false;
            foreach ($keywords as $keyword) {
                if (str_contains($bodyLower, $keyword)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missingPolicies[] = $topic;
            }
        }
        if ($missingPolicies !== []) {
            $warnings[] = sprintf(
                'No mention of: %s — an agent recommending your products has to guess these terms',
                implode(', ', $missingPolicies)
            );
        }

        // Links are what make the policies actionable — prose alone leaves the
        // agent paraphrasing rather than citing.
        preg_match_all('/https?:\/\/[^\s)>\]]+/', $body, $linkMatches);
        $links = array_unique($linkMatches[0]);
        if (count($links) < 2) {
            $warnings[] = 'Few or no links — policies should be linked so an agent can fetch and quote them';
        }

        $mentionsLlmsTxt = str_contains($bodyLower, 'llms.txt');
        if (!$mentionsLlmsTxt) {
            $warnings[] = 'Does not reference llms.txt — the two files should point at each other';
        }

        // ── Agentic discovery sitemap ───────────────────────────────────
        $sitemapUrl    = $base . self::AGENTIC_SITEMAP;
        $sitemapStatus = $this->statusCode($sitemapUrl);
        $hasSitemap    = $sitemapStatus === 200;
        if (!$hasSitemap) {
            $warnings[] = sprintf(
                'No %s (HTTP %s) — a small sitemap declaring agents.md and llms.txt is how they get found '
                . 'without guessing; Shopify publishes one',
                self::AGENTIC_SITEMAP,
                $sitemapStatus ?: 'error'
            );
        }

        $details = [
            'url'                => $url,
            'size_bytes'         => $size,
            'content_type'       => $contentType,
            'links'              => count($links),
            'missing_policies'   => $missingPolicies,
            'references_llms_txt' => $mentionsLlmsTxt,
            'agentic_sitemap'    => $hasSitemap,
            'last_modified'      => $headers['last-modified'] ?? null,
        ];

        if ($issues !== []) {
            return $this->fail(
                sprintf('agents.md has %d critical issue(s): %s', count($issues), $issues[0]),
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if ($warnings !== []) {
            return $this->warn(
                sprintf('agents.md present (%d bytes, %d link(s)) — %d improvement(s).', $size, count($links), count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('agents.md valid — policies covered, %d link(s), discovery sitemap present.', count($links)),
            $details
        );
    }
}
