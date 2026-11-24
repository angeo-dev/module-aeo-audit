<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\AgentDiscoveryChecker as C;
use PHPUnit\Framework\TestCase;

/**
 * 4.5.0: AI Catalog and MCP Server Card validation (pure helpers, no HTTP).
 */
class AgentDiscoveryCheckerTest extends TestCase
{
    private const CARD = [
        '$schema' => C::CARD_SCHEMA,
        'name' => 'dev.angeo.demo/mcp',
        'version' => '2.5.0',
        'description' => 'Product catalog and store information of Angeo Demo.',
        'remotes' => [['type' => 'streamable-http', 'url' => 'https://demo.angeo.dev/mcp',
            'supportedProtocolVersions' => ['2026-07-28', '2025-11-25']]],
    ];

    public function testMcpVersionsAndNote(): void
    {
        $cards = [
            ['status' => 200, 'protocol_versions' => ['2025-11-25', '2026-07-28']],
            ['status' => 404, 'protocol_versions' => ['2027-01-01']],
            ['status' => 200, 'protocol_versions' => ['2025-11-25', 'latest']],
        ];
        self::assertSame(['2026-07-28', '2025-11-25'], C::mcpVersions($cards));
        self::assertStringContainsString('the current revision', C::versionNote(['2026-07-28', '2025-11-25']));
        self::assertStringContainsString('not yet 2026-07-28', C::versionNote(['2025-11-25']));
        self::assertSame('', C::versionNote([]));
    }

    public function testLinkHeader(): void
    {
        self::assertSame('/.well-known/ai-catalog.json', C::catalogLinkFromHeader(
            '</llms.txt>; rel="describedby", </.well-known/ai-catalog.json>; rel="ai-catalog"'
        ));
        self::assertSame('https://a.example/c.json', C::catalogLinkFromHeader('<https://a.example/c.json>; rel=ai-catalog'));
        self::assertNull(C::catalogLinkFromHeader('</llms.txt>; rel="describedby"'));
        self::assertNull(C::catalogLinkFromHeader(''));
    }

    public function testLinkInHtml(): void
    {
        $html = '<head><link rel="alternate" href="/x.md"><link href="/.well-known/ai-catalog.json" rel="ai-catalog" type="application/ai-catalog+json"></head>';
        self::assertSame('/.well-known/ai-catalog.json', C::catalogLinkFromHtml($html));
        self::assertNull(C::catalogLinkFromHtml('<link rel="stylesheet" href="/a.css">'));
    }

    public function testAbsolute(): void
    {
        self::assertSame('https://s.example/.well-known/ai-catalog.json', C::absolute('/.well-known/ai-catalog.json', 'https://s.example/en'));
        self::assertSame('https://cdn.example/c.json', C::absolute('//cdn.example/c.json', 'https://s.example'));
        self::assertSame('https://x.example/c', C::absolute('https://x.example/c', 'https://s.example'));
    }

    public function testValidCatalog(): void
    {
        $r = C::validateCatalog((string) json_encode([
            'specVersion' => '1.0',
            'host' => ['displayName' => 'Angeo Demo', 'identifier' => 'demo.angeo.dev'],
            'entries' => [
                ['identifier' => 'urn:air:demo.angeo.dev:mcp:mcp', 'type' => C::CARD_TYPE, 'url' => 'https://demo.angeo.dev/mcp/server-card'],
                ['identifier' => 'urn:air:demo.angeo.dev:a2a:x', 'type' => 'application/a2a-agent-card+json', 'data' => ['name' => 'x']],
            ],
        ]));
        self::assertSame([], $r['problems']);
        self::assertSame(2, $r['summary']['entries']);
        self::assertSame(['https://demo.angeo.dev/mcp/server-card'], $r['card_urls']);
    }

    public function testArdNotesForEntryWithoutDisplayName(): void
    {
        // demo.angeo.dev, Nov 2026: valid AI Catalog 1.0, rejected by
        // isitagentready.com, which applies ARD v0.91.
        $r = C::validateCatalog((string) json_encode([
            'specVersion' => '1.0',
            'host' => ['displayName' => 'demo.angeo.dev', 'identifier' => 'demo.angeo.dev'],
            'entries' => [
                ['identifier' => 'urn:air:demo.angeo.dev:mcp:mcp', 'type' => C::CARD_TYPE, 'url' => 'https://demo.angeo.dev/mcp/server-card'],
            ],
        ]));
        self::assertSame([], $r['problems']);
        self::assertCount(2, $r['ard_notes']);
        self::assertStringContainsString('displayName', $r['ard_notes'][0]);
        self::assertStringContainsString('representativeQueries', $r['ard_notes'][1]);

        $ok = C::validateCatalog((string) json_encode([
            'specVersion' => '1.0',
            'entries' => [[
                'identifier' => 'urn:air:demo.angeo.dev:mcp:mcp', 'displayName' => 'Angeo Demo store',
                'type' => C::CARD_TYPE, 'url' => 'https://demo.angeo.dev/mcp/server-card',
                'representativeQueries' => ['find a yoga bag', 'is this jacket in stock'],
            ]],
        ]));
        self::assertSame([], $ok['ard_notes']);
    }

    public function testInvalidJsonStillHasArdNotes(): void
    {
        $r = C::validateCatalog('{not json');
        self::assertSame([], $r['ard_notes']);
        self::assertSame([], $r['card_urls']);
    }

    public function testBrokenCatalog(): void
    {
        $r = C::validateCatalog((string) json_encode([
            'host' => ['identifier' => 'x'],
            'collections' => [],
            'entries' => [
                ['type' => 'x', 'url' => 'https://a.example/'],
                ['identifier' => 'a', 'type' => 'x'],
                ['identifier' => 'a', 'type' => 'x', 'url' => 'http://a.example/'],
            ],
        ]));
        $all = implode(' ', $r['problems']);
        foreach (['specVersion', 'displayName', 'collections', 'no identifier', 'exactly one', 'repeats', 'HTTPS'] as $needle) {
            self::assertStringContainsString($needle, $all);
        }
        self::assertSame(['The AI Catalog is not valid JSON.'], C::validateCatalog('<html>')['problems']);
    }

    public function testValidCard(): void
    {
        $r = C::validateCard((string) json_encode(self::CARD));
        self::assertSame([], $r['problems']);
        self::assertSame('dev.angeo.demo/mcp', $r['summary']['name']);
        self::assertSame(['2026-07-28', '2025-11-25'], $r['summary']['protocol_versions']);
    }

    public function testOldFormatCardIsNamed(): void
    {
        $r = C::validateCard((string) json_encode(['serverInfo' => ['name' => 'x', 'version' => '1'], 'capabilities' => []]));
        self::assertStringContainsString('old card format', $r['problems'][0]);
    }

    public function testBadCardFields(): void
    {
        $card = self::CARD;
        $card['name'] = 'Angeo Demo';
        $card['description'] = str_repeat('a', 101);
        $card['remotes'] = [['type' => 'streamable-http', 'url' => 'http://demo.angeo.dev/mcp']];
        $all = implode(' ', C::validateCard((string) json_encode($card))['problems']);
        foreach (['reverse domain', '100 characters', 'not HTTPS'] as $needle) {
            self::assertStringContainsString($needle, $all);
        }
        unset($card['remotes']);
        self::assertStringContainsString('streamable-http', implode(' ', C::validateCard((string) json_encode($card))['problems']));
    }

    public function testWeightIsZero(): void
    {
        $ref = new \ReflectionClass(C::class);
        $c = $ref->newInstanceWithoutConstructor();
        self::assertSame(0.0, $c->getWeight());
        self::assertSame('agent_discovery', $c->getCode());
    }
}
