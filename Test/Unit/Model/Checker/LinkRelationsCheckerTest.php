<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\Checker\LinkRelationsChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\AeoAudit\Model\Checker\LinkRelationsChecker
 */
class LinkRelationsCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private const PRODUCT = 'https://example.com/blue-shirt.html';
    private const MIRROR  = 'https://example.com/blue-shirt.html.md';
    private const ALT     = 'https://example.com/blue-shirt.md';
    private const LLMS    = 'https://example.com/llms.txt';

    private LinkRelationsChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->urlSampler->method('getSampleProductUrl')->willReturn(self::PRODUCT);
        $this->checker = new LinkRelationsChecker($this->httpCache, $this->urlSampler);
    }

    public function testBothRelationsPresentAndResolvingPasses(): void
    {
        $this->stubUrl(self::PRODUCT, 200, $this->headWith(
            '<link rel="alternate" type="text/markdown" href="' . self::MIRROR . '"/>'
            . '<link rel="describedby" href="' . self::LLMS . '"/>'
        ));
        $this->stubUrl(self::MIRROR, 200, '# Blue shirt', ['link' => '<' . self::LLMS . '>; rel="describedby"']);
        $this->stubUrl(self::ALT, 200, '# Blue shirt');
        $this->stubUrl(self::LLMS, 200, '# Store');

        self::assertSame(CheckerInterface::STATUS_PASS, $this->checker->check($this->store)->getStatus());
    }

    public function testMissingRelationsFail(): void
    {
        $this->stubUrl(self::PRODUCT, 200, $this->headWith('<link rel="canonical" href="' . self::PRODUCT . '"/>'));

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        self::assertStringContainsString('text/markdown', $result->getRecommendation());
    }

    public function testRelationsInHttpHeaderCountTheSameAsInHead(): void
    {
        // The spec accepts either form; a store serving them as headers is
        // compliant and must not be marked down.
        $this->stubUrl(self::PRODUCT, 200, $this->headWith(''), [
            'link' => '<' . self::MIRROR . '>; rel="alternate"; type="text/markdown", '
                . '<' . self::LLMS . '>; rel="describedby"',
        ]);
        $this->stubUrl(self::MIRROR, 200, '# Blue shirt', ['link' => '<' . self::LLMS . '>; rel="describedby"']);
        $this->stubUrl(self::ALT, 200, '# Blue shirt');
        $this->stubUrl(self::LLMS, 200, '# Store');

        self::assertSame(CheckerInterface::STATUS_PASS, $this->checker->check($this->store)->getStatus());
    }

    public function testDeclaredButBrokenMirrorFails(): void
    {
        // Declaring a relation that 404s is worse than declaring none: the
        // agent follows it and fails.
        $this->stubUrl(self::PRODUCT, 200, $this->headWith(
            '<link rel="alternate" type="text/markdown" href="' . self::MIRROR . '"/>'
            . '<link rel="describedby" href="' . self::LLMS . '"/>'
        ));
        $this->stubUrl(self::MIRROR, 404, '');
        $this->stubUrl(self::LLMS, 200, '# Store');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_FAIL, $result->getStatus());
        self::assertStringContainsString('404', $result->getRecommendation());
    }

    public function testMissingLinkHeaderOnMirrorOnlyWarns(): void
    {
        $this->stubUrl(self::PRODUCT, 200, $this->headWith(
            '<link rel="alternate" type="text/markdown" href="' . self::MIRROR . '"/>'
            . '<link rel="describedby" href="' . self::LLMS . '"/>'
        ));
        $this->stubUrl(self::MIRROR, 200, '# Blue shirt');
        $this->stubUrl(self::ALT, 200, '# Blue shirt');
        $this->stubUrl(self::LLMS, 200, '# Store');

        $result = $this->checker->check($this->store);

        self::assertSame(CheckerInterface::STATUS_WARN, $result->getStatus());
        self::assertStringContainsString('Link:', $result->getRecommendation());
    }

    public function testNoSampleProductWarnsRatherThanFails(): void
    {
        $sampler = $this->createMock(\Angeo\AeoAudit\Service\StoreUrlSampler::class);
        $sampler->method('getBaseUrl')->willReturn('https://example.com');
        $sampler->method('getSampleProductUrl')->willReturn(null);

        $checker = new LinkRelationsChecker($this->httpCache, $sampler);

        self::assertSame(CheckerInterface::STATUS_WARN, $checker->check($this->store)->getStatus());
    }

    private function headWith(string $tags): string
    {
        return '<!doctype html><html><head><title>Blue shirt</title>' . $tags . '</head><body></body></html>';
    }
}
