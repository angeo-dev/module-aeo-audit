<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Service;

use Angeo\AeoAudit\Service\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    private RobotsTxtParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RobotsTxtParser();
    }

    public function testExplicitBlockOverridesWildcardAllow(): void
    {
        $rules = $this->parser->parse(
            "User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\n"
        );

        $this->assertTrue($this->parser->isBotBlocked('GPTBot', $rules));
        $this->assertFalse($this->parser->isBotBlocked('ClaudeBot', $rules));
    }

    public function testWildcardBlockAppliesToUnlistedBots(): void
    {
        $rules = $this->parser->parse("User-agent: *\nDisallow: /\n");

        $this->assertTrue($this->parser->isBotBlocked('PerplexityBot', $rules));
    }

    public function testExplicitAllowEscapesWildcardBlock(): void
    {
        $rules = $this->parser->parse(
            "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /\n"
        );

        $this->assertFalse($this->parser->isBotBlocked('OAI-SearchBot', $rules));
        $this->assertTrue($this->parser->isBotBlocked('GPTBot', $rules));
    }

    public function testGroupedAgentsShareRules(): void
    {
        $rules = $this->parser->parse(
            "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n"
        );

        $this->assertTrue($this->parser->isBotBlocked('GPTBot', $rules));
        $this->assertTrue($this->parser->isBotBlocked('ClaudeBot', $rules));
    }

    public function testInlineCommentsAreStripped(): void
    {
        $rules = $this->parser->parse(
            "User-agent: GPTBot # training crawler\nDisallow: / # everything\n"
        );

        $this->assertTrue($this->parser->isBotBlocked('GPTBot', $rules));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $rules = $this->parser->parse("User-agent: gptbot\nDisallow: /\n");

        $this->assertTrue($this->parser->isBotBlocked('GPTBot', $rules));
    }

    public function testEmptyDisallowMeansAllowed(): void
    {
        $rules = $this->parser->parse("User-agent: *\nDisallow:\n");

        $this->assertFalse($this->parser->isBotBlocked('GPTBot', $rules));
    }

    public function testCrawlDelayIsCaptured(): void
    {
        $rules = $this->parser->parse("User-agent: GPTBot\nCrawl-delay: 10\nDisallow: /admin\n");

        $this->assertSame('10', $rules['gptbot']['crawl_delay']);
        $this->assertFalse($this->parser->isBotBlocked('GPTBot', $rules));
    }
}
