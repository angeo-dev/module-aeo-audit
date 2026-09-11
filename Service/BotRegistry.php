<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

/**
 * Canonical, purpose-classified catalog of AI user agents — July 2026.
 *
 * Every AI crawler does one of three jobs, and since 2026 the major vendors
 * expose them as distinct bots with distinct user-agent strings:
 *
 *  - TRAINING: collects pages to train models. Blocking it is a legitimate
 *    licensing decision, NOT an AEO mistake — it does not remove the store
 *    from AI search answers.
 *  - SEARCH:   indexes pages for AI answer engines. Blocking it removes the
 *    store from citations (ChatGPT Search, Perplexity, etc.) — an AEO failure.
 *  - FETCHER:  user-triggered, real-time page fetch on behalf of a person
 *    asking the assistant right now. Several fetchers ignore robots.txt by
 *    design, so robots.txt rules against them are mostly ineffective.
 *
 * OPT_OUT_TOKEN entries (Google-Extended, Applebot-Extended) are directives
 * consumed by an existing crawler — they never make HTTP requests themselves
 * and therefore never appear in access logs.
 *
 * This registry is the single source of truth shared by RobotsTxtChecker,
 * WafRealityChecker, AiCrawlerActivityChecker and the frontend hit recorder,
 * so a bot is never classified two different ways in two places.
 *
 * @api
 * @since 4.0.0
 */
class BotRegistry
{
    public const CLASS_TRAINING     = 'training';
    public const CLASS_SEARCH      = 'search';
    public const CLASS_FETCHER     = 'fetcher';
    public const CLASS_OPT_OUT     = 'opt_out_token';

    /**
     * code => definition.
     *
     *  ua_token       — substring matched (case-insensitive) against the raw
     *                   User-Agent header in logs / live requests. Empty for
     *                   opt-out tokens (they never send requests).
     *  robots_token   — token used in robots.txt User-agent lines.
     *  respects_robots — whether the vendor documents robots.txt compliance
     *                   for this agent. Fetchers from Google do not; fetchers
     *                   from OpenAI and Anthropic do.
     *  search_critical — SEARCH-class only: blocking this bot removes the
     *                   store from a major answer engine (FAIL vs WARN).
     *
     * @var array<string, array{
     *     label: string,
     *     vendor: string,
     *     class: string,
     *     ua_token: string,
     *     robots_token: string,
     *     respects_robots: bool,
     *     search_critical: bool
     * }>
     */
    private const BOTS = [
        // ── OpenAI ──────────────────────────────────────────────────
        'gptbot' => [
            'label' => 'OpenAI training crawler', 'vendor' => 'OpenAI',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'GPTBot',
            'robots_token' => 'GPTBot', 'respects_robots' => true, 'search_critical' => false,
        ],
        'oai_searchbot' => [
            'label' => 'ChatGPT Search indexer', 'vendor' => 'OpenAI',
            'class' => self::CLASS_SEARCH, 'ua_token' => 'OAI-SearchBot',
            'robots_token' => 'OAI-SearchBot', 'respects_robots' => true, 'search_critical' => true,
        ],
        'chatgpt_user' => [
            'label' => 'ChatGPT live browsing agent', 'vendor' => 'OpenAI',
            'class' => self::CLASS_FETCHER, 'ua_token' => 'ChatGPT-User',
            'robots_token' => 'ChatGPT-User', 'respects_robots' => true, 'search_critical' => false,
        ],
        // ── Anthropic ───────────────────────────────────────────────
        'claudebot' => [
            'label' => 'Anthropic training crawler', 'vendor' => 'Anthropic',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'ClaudeBot',
            'robots_token' => 'ClaudeBot', 'respects_robots' => true, 'search_critical' => false,
        ],
        'claude_searchbot' => [
            'label' => 'Claude Search indexer', 'vendor' => 'Anthropic',
            'class' => self::CLASS_SEARCH, 'ua_token' => 'Claude-SearchBot',
            'robots_token' => 'Claude-SearchBot', 'respects_robots' => true, 'search_critical' => true,
        ],
        'claude_user' => [
            'label' => 'Claude live browsing agent', 'vendor' => 'Anthropic',
            'class' => self::CLASS_FETCHER, 'ua_token' => 'Claude-User',
            'robots_token' => 'Claude-User', 'respects_robots' => true, 'search_critical' => false,
        ],
        // ── Perplexity ──────────────────────────────────────────────
        'perplexitybot' => [
            'label' => 'Perplexity search indexer', 'vendor' => 'Perplexity',
            'class' => self::CLASS_SEARCH, 'ua_token' => 'PerplexityBot',
            'robots_token' => 'PerplexityBot', 'respects_robots' => true, 'search_critical' => true,
        ],
        'perplexity_user' => [
            'label' => 'Perplexity live browsing agent', 'vendor' => 'Perplexity',
            'class' => self::CLASS_FETCHER, 'ua_token' => 'Perplexity-User',
            'robots_token' => 'Perplexity-User', 'respects_robots' => false, 'search_critical' => false,
        ],
        // ── Google ──────────────────────────────────────────────────
        'google_extended' => [
            'label' => 'Gemini training & grounding opt-out token', 'vendor' => 'Google',
            'class' => self::CLASS_OPT_OUT, 'ua_token' => '',
            'robots_token' => 'Google-Extended', 'respects_robots' => true, 'search_critical' => false,
        ],
        // ── Apple ───────────────────────────────────────────────────
        'applebot' => [
            'label' => 'Apple Intelligence / Siri indexer', 'vendor' => 'Apple',
            'class' => self::CLASS_SEARCH, 'ua_token' => 'Applebot',
            'robots_token' => 'Applebot', 'respects_robots' => true, 'search_critical' => false,
        ],
        'applebot_extended' => [
            'label' => 'Apple foundation-model training opt-out token', 'vendor' => 'Apple',
            'class' => self::CLASS_OPT_OUT, 'ua_token' => '',
            'robots_token' => 'Applebot-Extended', 'respects_robots' => true, 'search_critical' => false,
        ],
        // ── Meta ────────────────────────────────────────────────────
        'meta_externalagent' => [
            'label' => 'Meta AI training crawler', 'vendor' => 'Meta',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'meta-externalagent',
            'robots_token' => 'Meta-ExternalAgent', 'respects_robots' => true, 'search_critical' => false,
        ],
        // ── Others ──────────────────────────────────────────────────
        'amazonbot' => [
            'label' => 'Amazon Alexa / Rufus crawler', 'vendor' => 'Amazon',
            'class' => self::CLASS_SEARCH, 'ua_token' => 'Amazonbot',
            'robots_token' => 'Amazonbot', 'respects_robots' => true, 'search_critical' => false,
        ],
        'bytespider' => [
            'label' => 'ByteDance crawler (undocumented, ignores robots.txt)', 'vendor' => 'ByteDance',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'Bytespider',
            'robots_token' => 'Bytespider', 'respects_robots' => false, 'search_critical' => false,
        ],
        'ccbot' => [
            'label' => 'Common Crawl (upstream corpus for many models)', 'vendor' => 'Common Crawl',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'CCBot',
            'robots_token' => 'CCBot', 'respects_robots' => true, 'search_critical' => false,
        ],
        'cohere_ai' => [
            'label' => 'Cohere crawler', 'vendor' => 'Cohere',
            'class' => self::CLASS_TRAINING, 'ua_token' => 'cohere-ai',
            'robots_token' => 'cohere-ai', 'respects_robots' => true, 'search_critical' => false,
        ],
        'mistral_user' => [
            'label' => 'Mistral Le Chat browsing agent', 'vendor' => 'Mistral',
            'class' => self::CLASS_FETCHER, 'ua_token' => 'MistralAI-User',
            'robots_token' => 'MistralAI-User', 'respects_robots' => true, 'search_critical' => false,
        ],
    ];

    /**
     * @return array<string, array{label: string, vendor: string, class: string,
     *     ua_token: string, robots_token: string, respects_robots: bool, search_critical: bool}>
     */
    public function all(): array
    {
        return self::BOTS;
    }

    /**
     * @return array<string, array{label: string, vendor: string, class: string,
     *     ua_token: string, robots_token: string, respects_robots: bool, search_critical: bool}>
     */
    public function byClass(string $class): array
    {
        return array_filter(self::BOTS, static fn(array $bot) => $bot['class'] === $class);
    }

    /**
     * All bots that actually make HTTP requests (excludes opt-out tokens).
     *
     * @return array<string, array{label: string, vendor: string, class: string,
     *     ua_token: string, robots_token: string, respects_robots: bool, search_critical: bool}>
     */
    public function requesters(): array
    {
        return array_filter(self::BOTS, static fn(array $bot) => $bot['ua_token'] !== '');
    }

    /**
     * Classify a raw User-Agent header. Returns the bot code or null when the
     * UA does not belong to a known AI agent.
     *
     * Order matters: more specific tokens are checked first so "OAI-SearchBot"
     * is never mis-bucketed by a looser token. The const array is already
     * ordered specific-before-generic per vendor.
     */
    public function classifyUserAgent(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        foreach (self::BOTS as $code => $bot) {
            if ($bot['ua_token'] !== '' && stripos($userAgent, $bot['ua_token']) !== false) {
                return $code;
            }
        }
        return null;
    }

    /**
     * @return array{label: string, vendor: string, class: string, ua_token: string,
     *     robots_token: string, respects_robots: bool, search_critical: bool}|null
     */
    public function get(string $code): ?array
    {
        return self::BOTS[$code] ?? null;
    }
}
