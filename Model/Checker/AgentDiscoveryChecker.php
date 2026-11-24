<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * AI Catalog and MCP Server Card — how an agent finds the store's endpoints.
 *
 * Two young formats, both checked here:
 *
 *  - AI Catalog (github.com/Agent-Card/ai-catalog, Editor's Draft, specVersion
 *    1.0). A JSON list of the site's agent endpoints. An agent looks for a
 *    `rel="ai-catalog"` link (HTML <link> or HTTP Link header) first, then for
 *    /.well-known/ai-catalog.json.
 *  - MCP Server Card (SEP-2127, schema v1). Describes an MCP server before
 *    connecting: name, version, where to connect. It lives at
 *    <streamable-http-url>/server-card. The old /.well-known/mcp path is not
 *    used by the spec.
 *
 * INFORMATIONAL. Weight 0: the result is shown, never counted in the score.
 * Both formats are drafts and few agents read them today; a store must not
 * lose points for not publishing them. A published file that is broken is
 * still reported as a warning, because then the store says something wrong.
 *
 * Not checked: whether the card matches what the server answers to
 * server/discover. That needs a POST to the MCP endpoint, which this audit
 * does not make.
 *
 * @since 4.5.0
 */
class AgentDiscoveryChecker extends AbstractChecker
{
    public const WELL_KNOWN_CATALOG = '/.well-known/ai-catalog.json';
    public const CATALOG_MEDIA_TYPE = 'application/ai-catalog+json';
    public const CARD_TYPE = 'application/mcp-server-card+json';
    public const CARD_SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

    /** Where angeo/module-mcp-server 2.5+ serves its card. Tried when no catalog points to one. */
    public const DEFAULT_CARD_PATH = '/mcp/server-card';

    private const MAX_CARDS = 3;

    /** Current MCP revision (stateless core, server/discover). Reported, never scored. @since 4.6.0 */
    public const CURRENT_MCP_VERSION = '2026-07-28';

    public function getName(): string
    {
        return 'Agent discovery — AI Catalog and MCP Server Card';
    }

    public function getCode(): string
    {
        return 'agent_discovery';
    }

    public function getWeight(): float
    {
        return 0.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt:^5.2 angeo/module-mcp-server:^2.6';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->normalizeBase($this->urlSampler->getBaseUrl($store));
        $details = ['base_url' => $base, 'informational' => true];
        $problems = [];

        // ── 1. Find the catalog ─────────────────────────────────────
        [$homeStatus, $homeBody, $homeHeaders] = $this->fetchWithHeaders($base . '/');
        $linked = self::catalogLinkFromHeader((string) ($homeHeaders['link'] ?? ''))
            ?? ($homeStatus === 200 ? self::catalogLinkFromHtml($homeBody) : null);
        $catalogUrl = $linked !== null ? self::absolute($linked, $base) : $base . self::WELL_KNOWN_CATALOG;
        $details['catalog_link'] = $linked !== null;
        $details['catalog_url'] = $catalogUrl;

        [$catStatus, $catBody, $catHeaders] = $this->fetchWithHeaders($catalogUrl);
        if ($catStatus !== 200 && $linked !== null) {
            $problems[] = sprintf('The page links to an AI Catalog at %s, but it answers HTTP %d.', $catalogUrl, $catStatus);
        }
        $details['catalog_status'] = $catStatus;

        $cardUrls = [];
        if ($catStatus === 200) {
            $report = self::validateCatalog($catBody);
            $details['catalog'] = $report['summary'];
            $problems = array_merge($problems, $report['problems']);
            if ($report['ard_notes'] !== []) {
                $details['ard_notes'] = $report['ard_notes'];
            }
            $type = strtolower(trim(explode(';', (string) ($catHeaders['content-type'] ?? ''))[0]));
            if ($type !== self::CATALOG_MEDIA_TYPE && $type !== 'application/json') {
                $problems[] = sprintf('The AI Catalog is served as "%s". Use %s.', $type ?: 'no type', self::CATALOG_MEDIA_TYPE);
            }
            $cardUrls = $report['card_urls'];
        }

        // ── 2. Server cards ─────────────────────────────────────────
        $fromCatalog = $cardUrls !== [];
        if (!$fromCatalog) {
            $cardUrls = [$base . self::DEFAULT_CARD_PATH];
        }
        $cards = [];
        foreach (array_slice($cardUrls, 0, self::MAX_CARDS) as $url) {
            [$status, $body] = $this->fetch($url);
            if ($status !== 200) {
                if ($fromCatalog) {
                    $problems[] = sprintf('The catalog lists a Server Card at %s, but it answers HTTP %d.', $url, $status);
                }
                $cards[] = ['url' => $url, 'status' => $status];
                continue;
            }
            $card = self::validateCard($body);
            $cards[] = ['url' => $url, 'status' => $status] + $card['summary'];
            foreach ($card['problems'] as $p) {
                $problems[] = 'Server Card ' . $url . ': ' . $p;
            }
        }
        $details['server_cards'] = $cards;
        $mcpVersions = self::mcpVersions($cards);
        $details['mcp_versions'] = $mcpVersions;
        $details['mcp_current'] = in_array(self::CURRENT_MCP_VERSION, $mcpVersions, true);
        $versionNote = self::versionNote($mcpVersions);

        $catalogFound = $catStatus === 200;
        $cardFound = array_filter($cards, static fn (array $c): bool => $c['status'] === 200) !== [];
        $details['catalog_found'] = $catalogFound;
        $details['server_card_found'] = $cardFound;

        if ($problems !== []) {
            $details['problems'] = $problems;

            return $this->warn(
                sprintf('Agent discovery files are published but have %d problem(s): %s', count($problems), $problems[0]),
                'Fix the listed problems. A wrong catalog or card sends agents to the wrong place. '
                    . 'This signal is informational and does not change the score.',
                $details
            );
        }
        if (!$catalogFound && !$cardFound) {
            return $this->pass(
                'No AI Catalog and no MCP Server Card. Both are drafts and optional — informational only.',
                $details + ['note' => 'Publish them when you run an MCP server: angeo/module-llms-txt 5.1 and angeo/module-mcp-server 2.5 do it for Magento.']
            );
        }
        if (!$catalogFound) {
            return $this->pass(
                sprintf('MCP Server Card is valid%s. No AI Catalog points to it yet — informational only.', $versionNote),
                $details + ['note' => 'An AI Catalog at ' . self::WELL_KNOWN_CATALOG . ' lets agents find the card without guessing its URL.']
            );
        }

        return $this->pass(
            sprintf('AI Catalog is valid (%d entr%s)%s.%s',
                $details['catalog']['entries'],
                $details['catalog']['entries'] === 1 ? 'y' : 'ies',
                $cardFound ? ' and the MCP Server Card is valid' . $versionNote : '',
                isset($details['ard_notes']) ? ' For ARD: ' . $details['ard_notes'][0] : ''),
            $details
        );
    }

    // ── Pure helpers (unit-tested without HTTP) ──────────────────────

    /**
     * MCP revisions the readable Server Cards declare, newest first.
     *
     * @param list<array<string,mixed>> $cards
     * @return list<string>
     */
    public static function mcpVersions(array $cards): array
    {
        $versions = [];
        foreach ($cards as $card) {
            if (($card['status'] ?? 0) !== 200) {
                continue;
            }
            foreach ((array) ($card['protocol_versions'] ?? []) as $v) {
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1) {
                    $versions[] = $v;
                }
            }
        }
        $versions = array_values(array_unique($versions));
        rsort($versions, SORT_STRING);

        return $versions;
    }

    /**
     * Short text for the result message: which MCP revisions the card lists.
     *
     * @param list<string> $versions
     */
    public static function versionNote(array $versions): string
    {
        if ($versions === []) {
            return '';
        }
        if (in_array(self::CURRENT_MCP_VERSION, $versions, true)) {
            return sprintf(' (MCP %s, the current revision)', self::CURRENT_MCP_VERSION);
        }

        return sprintf(' (MCP %s — not yet %s, the current revision)', implode(', ', $versions), self::CURRENT_MCP_VERSION);
    }

    public static function catalogLinkFromHeader(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>\s*;(.*)$/', trim($part), $m) === 1
                && preg_match('/\brel\s*=\s*"?([^";]*)"?/i', $m[2], $r) === 1
                && in_array('ai-catalog', preg_split('/\s+/', strtolower(trim($r[1]))) ?: [], true)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    public static function catalogLinkFromHtml(string $html): ?string
    {
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags) < 1) {
            return null;
        }
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rel) === 1
                && in_array('ai-catalog', preg_split('/\s+/', strtolower(trim($rel[1]))) ?: [], true)
                && preg_match('/\bhref\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $href) === 1) {
                return html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5);
            }
        }

        return null;
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $base);
        if (str_starts_with($href, '//')) {
            return (string) parse_url($base, PHP_URL_SCHEME) . ':' . $href;
        }

        return str_starts_with($href, '/') ? $origin . $href : rtrim($base, '/') . '/' . $href;
    }

    /**
     * @return array{summary: array<string,mixed>, problems: list<string>, card_urls: list<string>, ard_notes: list<string>}
     */
    public static function validateCatalog(string $body): array
    {
        $doc = json_decode($body, true, 32);
        if (!is_array($doc)) {
            return ['summary' => ['entries' => 0], 'problems' => ['The AI Catalog is not valid JSON.'], 'card_urls' => [], 'ard_notes' => []];
        }
        $problems = [];
        if (!is_string($doc['specVersion'] ?? null)) {
            $problems[] = 'specVersion is missing.';
        }
        if (isset($doc['host']) && (!is_array($doc['host']) || !is_string($doc['host']['displayName'] ?? null))) {
            $problems[] = 'host is present but has no displayName.';
        }
        $entries = $doc['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            $problems[] = 'entries is missing or not a list.';
            $entries = [];
        }
        if (isset($doc['collections'])) {
            $problems[] = '"collections" was removed from the draft; agents ignore it.';
        }

        $types = [];
        $ids = [];
        $cardUrls = [];
        // Notes for ARD v0.91 (agenticresourcediscovery.org). Not problems:
        // AI Catalog 1.0 makes displayName optional, ARD requires it, and
        // isitagentready.com follows ARD.
        $ardNotes = [];
        foreach ($entries as $i => $e) {
            $n = $i + 1;
            if (!is_array($e)) {
                $problems[] = "Entry $n is not an object.";
                continue;
            }
            $id = $e['identifier'] ?? null;
            if (!is_string($id) || $id === '') {
                $problems[] = "Entry $n has no identifier.";
            } elseif (isset($ids[$id])) {
                $problems[] = "Entry $n repeats identifier $id.";
            } else {
                $ids[$id] = true;
            }
            $type = $e['type'] ?? null;
            if (!is_string($type) || $type === '') {
                $problems[] = "Entry $n has no type.";
                $type = '';
            }
            $hasUrl = array_key_exists('url', $e);
            $hasData = array_key_exists('data', $e);
            if ($hasUrl === $hasData) {
                $problems[] = "Entry $n must have exactly one of url or data.";
            } elseif ($hasUrl && (!is_string($e['url']) || preg_match('#^https://#i', $e['url']) !== 1)) {
                $problems[] = "Entry $n url is not an HTTPS URL.";
            } elseif ($hasUrl && $type === self::CARD_TYPE) {
                $cardUrls[] = $e['url'];
            }
            if ($type !== '') {
                $types[$type] = ($types[$type] ?? 0) + 1;
            }
            if (!is_string($e['displayName'] ?? null) || trim($e['displayName']) === '') {
                $ardNotes[] = "Entry $n has no displayName. AI Catalog allows this; ARD v0.91 requires it, so ARD checkers such as isitagentready.com reject the catalog.";
            }
            $queries = $e['representativeQueries'] ?? null;
            if (!is_array($queries) || count($queries) < 2 || count($queries) > 5) {
                $ardNotes[] = "Entry $n has no 2–5 representativeQueries. ARD v0.91 recommends them for registry search.";
            }
        }

        return [
            'summary' => [
                'spec_version' => is_string($doc['specVersion'] ?? null) ? $doc['specVersion'] : null,
                'host' => is_string($doc['host']['displayName'] ?? null) ? $doc['host']['displayName'] : null,
                'entries' => count($entries),
                'types' => $types,
            ],
            'problems' => $problems,
            'card_urls' => array_values(array_unique($cardUrls)),
            'ard_notes' => $ardNotes,
        ];
    }

    /**
     * @return array{summary: array<string,mixed>, problems: list<string>}
     */
    public static function validateCard(string $body): array
    {
        $card = json_decode($body, true, 32);
        if (!is_array($card)) {
            return ['summary' => [], 'problems' => ['not valid JSON.']];
        }
        $problems = [];
        if (($card['$schema'] ?? null) !== self::CARD_SCHEMA) {
            $problems[] = isset($card['serverInfo'])
                ? 'old card format (serverInfo). Schema v1 uses $schema, name, version, description, remotes.'
                : '$schema is missing or not the v1 schema URL.';
        }
        $name = $card['name'] ?? null;
        if (!is_string($name) || strlen($name) > 200 || preg_match('#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $name) !== 1) {
            $problems[] = 'name must look like "com.example/server" (reverse domain, a slash, a name).';
        }
        if (!is_string($card['version'] ?? null) || $card['version'] === '') {
            $problems[] = 'version is missing.';
        }
        $desc = $card['description'] ?? null;
        if (!is_string($desc) || $desc === '') {
            $problems[] = 'description is missing.';
        } elseif (mb_strlen($desc) > 100) {
            $problems[] = 'description is longer than 100 characters.';
        }
        if (isset($card['title']) && (!is_string($card['title']) || mb_strlen($card['title']) > 100)) {
            $problems[] = 'title is longer than 100 characters.';
        }
        $remote = null;
        foreach ((array) ($card['remotes'] ?? []) as $r) {
            if (is_array($r) && ($r['type'] ?? '') === 'streamable-http' && is_string($r['url'] ?? null)) {
                $remote = $r;
                break;
            }
        }
        if ($remote === null) {
            $problems[] = 'no streamable-http remote, so an agent does not know where to connect.';
        } elseif (preg_match('#^https://#i', $remote['url']) !== 1) {
            $problems[] = 'the remote URL is not HTTPS.';
        }

        return [
            'summary' => [
                'name' => is_string($name) ? $name : null,
                'version' => is_string($card['version'] ?? null) ? $card['version'] : null,
                'remote_url' => $remote['url'] ?? null,
                'protocol_versions' => array_values(array_filter((array) ($remote['supportedProtocolVersions'] ?? []), 'is_string')),
            ],
            'problems' => $problems,
        ];
    }
}
