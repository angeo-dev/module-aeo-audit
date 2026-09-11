<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Service;

use Angeo\AeoAudit\Service\BotRegistry;
use PHPUnit\Framework\TestCase;

class BotRegistryTest extends TestCase
{
    private BotRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new BotRegistry();
    }

    public function testClassifiesRealWorldUserAgents(): void
    {
        $cases = [
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot'
                => 'gptbot',
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot'
                => 'oai_searchbot',
            'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'
                => 'claudebot',
            'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)'
                => 'perplexitybot',
            'Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)'
                => 'bytespider',
            'CCBot/2.0 (https://commoncrawl.org/faq/)'
                => 'ccbot',
        ];

        foreach ($cases as $ua => $expected) {
            $this->assertSame($expected, $this->registry->classifyUserAgent($ua), 'UA: ' . $ua);
        }
    }

    public function testSearchBotIsNotMistakenForTrainingBot(): void
    {
        // "OAI-SearchBot" must never be bucketed as GPTBot and vice versa.
        $this->assertSame(
            'oai_searchbot',
            $this->registry->classifyUserAgent('compatible; OAI-SearchBot/1.0')
        );
        $this->assertSame(
            'claude_searchbot',
            $this->registry->classifyUserAgent('compatible; Claude-SearchBot/1.0')
        );
        $this->assertSame(
            'claude_user',
            $this->registry->classifyUserAgent('compatible; Claude-User/1.0')
        );
    }

    public function testRegularBrowserIsNotClassified(): void
    {
        $this->assertNull($this->registry->classifyUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
        ));
        $this->assertNull($this->registry->classifyUserAgent(''));
        $this->assertNull($this->registry->classifyUserAgent(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ));
    }

    public function testOptOutTokensNeverMatchUserAgents(): void
    {
        // Google-Extended is a robots.txt directive, not a crawler — it must
        // never be produced by UA classification.
        $this->assertNull($this->registry->classifyUserAgent('Google-Extended'));

        foreach ($this->registry->byClass(BotRegistry::CLASS_OPT_OUT) as $bot) {
            $this->assertSame('', $bot['ua_token']);
        }
    }

    public function testRequestersExcludeOptOutTokens(): void
    {
        foreach ($this->registry->requesters() as $bot) {
            $this->assertNotSame(BotRegistry::CLASS_OPT_OUT, $bot['class']);
            $this->assertNotSame('', $bot['ua_token']);
        }
    }

    public function testEveryClassIsRepresented(): void
    {
        $this->assertNotEmpty($this->registry->byClass(BotRegistry::CLASS_TRAINING));
        $this->assertNotEmpty($this->registry->byClass(BotRegistry::CLASS_SEARCH));
        $this->assertNotEmpty($this->registry->byClass(BotRegistry::CLASS_FETCHER));
        $this->assertNotEmpty($this->registry->byClass(BotRegistry::CLASS_OPT_OUT));
    }

    public function testSearchCriticalFlagOnlyOnSearchClass(): void
    {
        foreach ($this->registry->all() as $code => $bot) {
            if ($bot['search_critical']) {
                $this->assertSame(
                    BotRegistry::CLASS_SEARCH,
                    $bot['class'],
                    sprintf('%s is search_critical but not search-class', $code)
                );
            }
        }
    }
}
