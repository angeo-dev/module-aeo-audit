<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\AgentCardChecker;
use PHPUnit\Framework\TestCase;

class AgentCardCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private const BASE = 'https://example.com';
    private const CANONICAL = self::BASE . '/.well-known/agent-card.json';
    private const LEGACY = self::BASE . '/.well-known/agent.json';
    private const UCP = self::BASE . '/.well-known/ucp';

    private AgentCardChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks(self::BASE);
        $this->checker = new AgentCardChecker($this->httpCache, $this->urlSampler);
    }

    /** @param array<string, mixed> $overrides */
    private function card(array $overrides = []): string
    {
        return (string)json_encode($overrides + [
            'name' => 'Example Store Agent',
            'url' => self::BASE . '/a2a',
            'version' => '1.0.0',
            'description' => 'Sells things.',
            'capabilities' => ['streaming' => true],
            'skills' => [['id' => 'search', 'name' => 'Catalog search']],
        ]);
    }

    private function ucpWithA2a(): string
    {
        return (string)json_encode([
            'ucp' => [
                'version' => '2026-04-08',
                'services' => [
                    'dev.ucp.shopping' => [
                        ['version' => '2026-04-08', 'transport' => 'rest', 'endpoint' => self::BASE . '/ucp/v1'],
                        ['version' => '2026-04-08', 'transport' => 'a2a', 'endpoint' => self::LEGACY],
                    ],
                ],
            ],
        ]);
    }

    private function ucpWithoutA2a(): string
    {
        return (string)json_encode([
            'ucp' => [
                'version' => '2026-04-08',
                'services' => [
                    'dev.ucp.shopping' => [
                        ['version' => '2026-04-08', 'transport' => 'rest', 'endpoint' => self::BASE . '/ucp/v1'],
                    ],
                ],
            ],
        ]);
    }

    public function testPassesWhenCardIsCompleteAtCanonicalPath(): void
    {
        $this->stubUrl(self::CANONICAL, 200, $this->card());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isPassed(), 'Expected PASS, got ' . $result->getStatus());
        $this->assertSame('Example Store Agent', $result->getDetails()['declared_name']);
    }

    public function testPassesWhenNoCardAndNoA2aTransportDeclared(): void
    {
        $this->stubUrl(self::UCP, 200, $this->ucpWithoutA2a());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isPassed(), 'A store that never opted into A2A must not be penalised.');
        $this->assertFalse($result->getDetails()['applicable']);
        $this->assertFalse($result->getDetails()['ucp_declares_a2a']);
    }

    public function testFailsWhenA2aDeclaredButNoCardServed(): void
    {
        $this->stubUrl(self::UCP, 200, $this->ucpWithA2a());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'Expected FAIL, got ' . $result->getStatus());
        $this->assertTrue($result->getDetails()['ucp_declares_a2a']);
    }

    public function testFailsWhenA2aDeclaredAndCardOnlyAtLegacyPath(): void
    {
        $this->stubUrl(self::UCP, 200, $this->ucpWithA2a());
        $this->stubUrl(self::LEGACY, 200, $this->card());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'Expected FAIL, got ' . $result->getStatus());
        $this->assertSame(200, $result->getDetails()['legacy_status']);
    }

    public function testWarnsWhenCardOnlyAtLegacyPathWithoutA2aDeclared(): void
    {
        $this->stubUrl(self::LEGACY, 200, $this->card());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning(), 'Expected WARN, got ' . $result->getStatus());
    }

    public function testWarnsWhenCardServedAtBothPaths(): void
    {
        $this->stubUrl(self::CANONICAL, 200, $this->card());
        $this->stubUrl(self::LEGACY, 200, $this->card());

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning(), 'Expected WARN, got ' . $result->getStatus());
    }

    public function testFailsWhenCardIsMissingRequiredFields(): void
    {
        $this->stubUrl(self::CANONICAL, 200, (string)json_encode(['name' => 'Agent', 'description' => 'x']));

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'Expected FAIL, got ' . $result->getStatus());
        $this->assertContains('url', $result->getDetails()['missing_required']);
        $this->assertContains('version', $result->getDetails()['missing_required']);
    }

    public function testFailsWhenCardIsNotValidJson(): void
    {
        $this->stubUrl(self::CANONICAL, 200, 'not json at all');

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isFailed(), 'Expected FAIL, got ' . $result->getStatus());
    }

    public function testWarnsWhenCardIsThin(): void
    {
        $this->stubUrl(self::CANONICAL, 200, (string)json_encode([
            'name' => 'Agent',
            'url' => self::BASE . '/a2a',
            'version' => '1.0.0',
        ]));

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->isWarning(), 'Expected WARN, got ' . $result->getStatus());
        $this->assertContains('skills', $result->getDetails()['missing_expected']);
    }

    public function testDetectsA2aTransportRegardlessOfManifestNesting(): void
    {
        $this->stubUrl(self::UCP, 200, (string)json_encode([
            'ucp' => ['services' => ['x' => ['y' => ['deep' => ['transport' => 'A2A']]]]],
        ]));

        $result = $this->checker->check($this->store);

        $this->assertTrue($result->getDetails()['ucp_declares_a2a'], 'Transport lookup must be structural.');
    }
}
