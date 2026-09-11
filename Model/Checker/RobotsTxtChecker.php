<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\BotRegistry;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\RobotsTxtParser;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;

/**
 * robots.txt validation with purpose-classified AI bot grading.
 *
 * v4 grading model — bots are judged by what they DO, not lumped together:
 *
 *  - SEARCH-class blocked   → FAIL when search_critical (removes the store
 *    from ChatGPT Search / Perplexity / Claude citations), WARN otherwise.
 *  - TRAINING-class blocked → informational note only. Opting out of model
 *    training is a legitimate licensing decision, not an AEO mistake, and
 *    does NOT remove the store from AI answers.
 *  - FETCHER-class blocked  → WARN with a caveat: several fetchers ignore
 *    robots.txt by design, so the directive is partly symbolic.
 *  - OPT-OUT tokens (Google-Extended, Applebot-Extended) — noted when
 *    present; blocking them trades AI-feature grounding for training
 *    opt-out, flagged as a conscious-choice notice, not an error.
 *
 * Retains v3 syntax validation: versioned UAs, Crawl-delay on bots that
 * ignore it, conflicting root rules, sitemap directive quality.
 *
 * @since 4.0.0 — grading rewritten around BotRegistry; parsing moved to
 *                the shared RobotsTxtParser service.
 */
class RobotsTxtChecker extends AbstractChecker
{
    /** Bots that documentedly ignore Crawl-delay — warning if present. */
    private const IGNORES_CRAWL_DELAY = ['GPTBot', 'ClaudeBot', 'Google-Extended'];

    public function __construct(
        HttpCache                        $httpCache,
        StoreUrlSampler                  $urlSampler,
        private readonly BotRegistry     $botRegistry,
        private readonly RobotsTxtParser $parser,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'robots.txt — AI bot access';
    }

    public function getCode(): string
    {
        return 'robots_txt';
    }

    public function getWeight(): float
    {
        return 1.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-robots-txt-aeo';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);
        [$status, $body] = $this->fetch($base . '/robots.txt');

        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'robots.txt not found or returned HTTP ' . ($status ?: 'error') . '.',
                'Create robots.txt at your store root and explicitly allow AI search crawlers '
                . '(OAI-SearchBot, PerplexityBot, Claude-SearchBot).',
                ['url' => $base . '/robots.txt', 'http_status' => $status]
            );
        }

        $rules = $this->parser->parse($body);

        $blockedSearchCritical = [];
        $blockedSearch         = [];
        $blockedTraining       = [];
        $blockedFetchers       = [];
        $optOutTokensBlocked   = [];
        $allowed               = [];

        foreach ($this->botRegistry->all() as $bot) {
            $isBlocked = $this->parser->isBotBlocked($bot['robots_token'], $rules);

            if (!$isBlocked) {
                $allowed[] = $bot['robots_token'];
                continue;
            }

            switch ($bot['class']) {
                case BotRegistry::CLASS_SEARCH:
                    if ($bot['search_critical']) {
                        $blockedSearchCritical[] = $bot['robots_token'];
                    } else {
                        $blockedSearch[] = $bot['robots_token'];
                    }
                    break;
                case BotRegistry::CLASS_TRAINING:
                    $blockedTraining[] = $bot['robots_token'];
                    break;
                case BotRegistry::CLASS_FETCHER:
                    $blockedFetchers[] = $bot['robots_token'];
                    break;
                case BotRegistry::CLASS_OPT_OUT:
                    $optOutTokensBlocked[] = $bot['robots_token'];
                    break;
            }
        }

        $sitemapIssues = $this->validateSitemapDirective($body);
        $hasSitemap    = $sitemapIssues['present'];
        $syntaxIssues  = $this->detectSyntaxIssues($body, $rules);

        $details = [
            'url'                    => $base . '/robots.txt',
            'allowed'                => $allowed,
            'blocked_search'         => array_merge($blockedSearchCritical, $blockedSearch),
            'blocked_training'       => $blockedTraining,
            'blocked_fetchers'       => $blockedFetchers,
            'ai_feature_opt_outs'    => $optOutTokensBlocked,
            'training_opt_out_note'  => $blockedTraining !== []
                ? 'Blocking training crawlers is a licensing choice, not an AEO error — '
                  . 'it does not remove the store from AI search answers.'
                : '',
            'sitemap_listed'         => $hasSitemap,
            'sitemap_issues'         => $sitemapIssues['issues'],
            'syntax_issues'          => $syntaxIssues,
        ];

        // ── FAIL: a critical answer-engine indexer is locked out ──────────
        if ($blockedSearchCritical !== []) {
            return $this->fail(
                sprintf(
                    'AI SEARCH crawler(s) blocked: %s — the store is invisible to those answer engines.',
                    implode(', ', $blockedSearchCritical)
                ),
                sprintf(
                    "These bots index for AI search (citations), they do not train models. Add:\n%s",
                    implode("\n\n", array_map(
                        static fn(string $b) => "User-agent: $b\nAllow: /",
                        $blockedSearchCritical
                    ))
                ),
                $details
            );
        }

        // ── WARN aggregation ──────────────────────────────────────────────
        $warnings = [];
        if ($blockedSearch !== []) {
            $warnings[] = sprintf('Search crawler(s) blocked: %s', implode(', ', $blockedSearch));
        }
        if ($blockedFetchers !== []) {
            $warnings[] = sprintf(
                'Live-fetch agent(s) blocked: %s — users asking assistants about this store get no page access '
                . '(note: some fetchers ignore robots.txt, making the rule partly symbolic)',
                implode(', ', $blockedFetchers)
            );
        }
        if ($optOutTokensBlocked !== []) {
            $warnings[] = sprintf(
                'AI-feature opt-out token(s) active: %s — trades Gemini/Apple grounding for training opt-out',
                implode(', ', $optOutTokensBlocked)
            );
        }
        if (!$hasSitemap) {
            $warnings[] = 'Sitemap directive not declared in robots.txt';
        }
        if ($sitemapIssues['issues'] !== []) {
            $warnings = array_merge($warnings, $sitemapIssues['issues']);
        }
        if ($syntaxIssues !== []) {
            $warnings = array_merge($warnings, $syntaxIssues);
        }

        if ($warnings !== []) {
            return $this->warn(
                sprintf('%d bot(s) permitted — %d issue(s) found', count($allowed), count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        // ── PASS — training opt-outs (if any) are reported, never punished ─
        $trainingNote = $blockedTraining !== []
            ? sprintf(' Training crawlers opted out by choice: %s.', implode(', ', $blockedTraining))
            : '';

        return $this->pass(
            sprintf(
                'All AI search & fetch agents permitted. Sitemap declared. No syntax issues.%s',
                $trainingNote
            ),
            $details
        );
    }

    /**
     * @return array{present: bool, issues: string[]}
     */
    private function validateSitemapDirective(string $body): array
    {
        $present = false;
        $issues  = [];

        if (preg_match_all('/^\s*sitemap:\s*(\S+)/im', $body, $m)) {
            $present = true;
            foreach ($m[1] as $url) {
                if (stripos($url, 'http://') === 0) {
                    $issues[] = sprintf('Sitemap URL "%s" is HTTP — AI crawlers prefer HTTPS', $url);
                }
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    $issues[] = sprintf('Sitemap URL "%s" is not a valid absolute URL', $url);
                }
            }
        }

        return ['present' => $present, 'issues' => $issues];
    }

    /**
     * @param array<string, array{allow: string[], disallow: string[], crawl_delay: string|null}> $rules
     * @return string[]
     */
    private function detectSyntaxIssues(string $body, array $rules): array
    {
        $issues = [];

        // Versioned UAs (e.g. "GPTBot/1.0") — robots.txt parsers don't strip versions
        if (preg_match_all('/^\s*user-agent:\s*(\S+\/\d)/im', $body, $m)) {
            foreach ($m[1] as $ua) {
                $issues[] = sprintf('UA "%s" includes version — robots.txt match is exact, drop the version', $ua);
            }
        }

        // Crawl-delay on bots that ignore it
        foreach (self::IGNORES_CRAWL_DELAY as $bot) {
            $botLower = strtolower($bot);
            if (isset($rules[$botLower]) && $rules[$botLower]['crawl_delay'] !== null) {
                $issues[] = sprintf('%s ignores Crawl-delay — directive has no effect', $bot);
            }
        }

        // Conflicting Allow: / + Disallow: / on the same group
        foreach ($rules as $agent => $rule) {
            if ($this->parser->blocksRoot($rule['disallow']) && $this->parser->allowsRoot($rule['allow'])) {
                $issues[] = sprintf(
                    'Agent "%s" has both Allow: / and Disallow: / — Allow wins, but the conflict is suspicious',
                    $agent
                );
            }
        }

        return $issues;
    }
}
