<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Deep robots.txt validation for AI bot access.
 *
 * v3 enhancements:
 *  - Syntax validation: conflicting rules, versioned UAs, Crawl-delay for
 *    bots that ignore it, non-HTTPS sitemap URLs
 *  - Reports the actual matched ruleset for transparency
 *  - Wildcard agents matched case-insensitively per robots.txt spec
 */
class RobotsTxtChecker extends AbstractChecker
{
    /**
     * Canonical AI bot list — May 2026.
     * critical=true means blocking this bot is a FAIL, not just WARN.
     *
     * @var array<string, array{label: string, critical: bool}>
     */
    private const AI_BOTS = [
        'GPTBot'          => ['label' => 'OpenAI crawler',               'critical' => true],
        'OAI-SearchBot'   => ['label' => 'OpenAI Shopping indexer',      'critical' => true],
        'ChatGPT-User'    => ['label' => 'ChatGPT browsing agent',       'critical' => false],
        'ClaudeBot'       => ['label' => 'Anthropic / Claude',           'critical' => false],
        'anthropic-ai'    => ['label' => 'Anthropic alt UA',             'critical' => false],
        'Claude-User'     => ['label' => 'Claude browsing agent',        'critical' => false],
        'PerplexityBot'   => ['label' => 'Perplexity AI',                'critical' => false],
        'Google-Extended' => ['label' => 'Google AI Overviews / Gemini', 'critical' => true],
        'Applebot'        => ['label' => 'Apple AI / Siri',              'critical' => false],
        'cohere-ai'       => ['label' => 'Cohere',                       'critical' => false],
        'Amazonbot'       => ['label' => 'Amazon Alexa AI',              'critical' => false],
        'Meta-ExternalAgent' => ['label' => 'Meta AI',                   'critical' => false],
    ];

    /** Bots that documentedly ignore Crawl-delay — warning if present. */
    private const IGNORES_CRAWL_DELAY = ['GPTBot', 'ClaudeBot', 'Google-Extended'];

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
                'Create robots.txt at your store root and explicitly allow AI crawlers.',
                ['url' => $base . '/robots.txt', 'http_status' => $status]
            );
        }

        $rules           = $this->parseRobotsTxt($body);
        $blocked         = [];
        $blockedCritical = [];
        $allowed         = [];

        foreach (self::AI_BOTS as $bot => $meta) {
            if ($this->isBotBlocked($bot, $rules)) {
                $blocked[] = $bot;
                if ($meta['critical']) {
                    $blockedCritical[] = $bot;
                }
            } else {
                $allowed[] = $bot;
            }
        }

        // Bonus: sitemap directive presence and quality
        $sitemapIssues = $this->validateSitemapDirective($body);
        $hasSitemap    = $sitemapIssues['present'];

        // Bonus: syntax issues (Crawl-delay on bots that ignore it, versioned UAs, etc.)
        $syntaxIssues  = $this->detectSyntaxIssues($body, $rules);

        $details = [
            'url'              => $base . '/robots.txt',
            'blocked'          => $blocked,
            'allowed'          => $allowed,
            'sitemap_listed'   => $hasSitemap,
            'sitemap_issues'   => $sitemapIssues['issues'],
            'syntax_issues'    => $syntaxIssues,
        ];

        if (!empty($blockedCritical)) {
            return $this->fail(
                sprintf('Critical AI bots blocked: %s', implode(', ', $blockedCritical)),
                sprintf(
                    "Add to robots.txt:\n%s",
                    implode("\n\n", array_map(
                        static fn($b) => "User-agent: $b\nAllow: /",
                        $blockedCritical
                    ))
                ),
                $details
            );
        }

        $warnings = [];
        if (!empty($blocked)) {
            $warnings[] = sprintf('%d AI bot(s) blocked: %s', count($blocked), implode(', ', $blocked));
        }
        if (!$hasSitemap) {
            $warnings[] = 'Sitemap directive not declared in robots.txt';
        }
        if (!empty($sitemapIssues['issues'])) {
            $warnings = array_merge($warnings, $sitemapIssues['issues']);
        }
        if (!empty($syntaxIssues)) {
            $warnings = array_merge($warnings, $syntaxIssues);
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('%d AI bot(s) permitted — %d issue(s) found', count($allowed), count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('All %d AI bots permitted. Sitemap declared. No syntax issues.', count(self::AI_BOTS)),
            $details
        );
    }

    /**
     * @return array<string, array{allow: string[], disallow: string[], crawl_delay: string|null}>
     */
    private function parseRobotsTxt(string $content): array
    {
        $rules         = [];
        $currentAgents = [];

        foreach (explode("\n", $content) as $rawLine) {
            $line = trim(explode('#', $rawLine)[0]); // strip inline comments

            if ($line === '') {
                $currentAgents = []; // blank line resets agent block
                continue;
            }

            if (stripos($line, 'user-agent:') === 0) {
                $agent = strtolower(trim(substr($line, 11)));
                $currentAgents[] = $agent;
                $rules[$agent] ??= ['allow' => [], 'disallow' => [], 'crawl_delay' => null];
                continue;
            }

            if (stripos($line, 'disallow:') === 0) {
                $path = trim(substr($line, 9));
                foreach ($currentAgents as $agent) {
                    $rules[$agent]['disallow'][] = $path;
                }
                continue;
            }

            if (stripos($line, 'allow:') === 0) {
                $path = trim(substr($line, 6));
                foreach ($currentAgents as $agent) {
                    $rules[$agent]['allow'][] = $path;
                }
                continue;
            }

            if (stripos($line, 'crawl-delay:') === 0) {
                $value = trim(substr($line, 12));
                foreach ($currentAgents as $agent) {
                    $rules[$agent]['crawl_delay'] = $value;
                }
            }
        }

        return $rules;
    }

    /**
     * @param array<string, array{allow: string[], disallow: string[], crawl_delay: string|null}> $rules
     */
    private function isBotBlocked(string $bot, array $rules): bool
    {
        $botLower = strtolower($bot);

        // Explicit bot entry takes precedence over wildcard
        if (isset($rules[$botLower])) {
            $allow    = $rules[$botLower]['allow']    ?? [];
            $disallow = $rules[$botLower]['disallow'] ?? [];

            if ($this->pathListBlocksRoot($disallow) && !$this->pathListAllowsRoot($allow)) {
                return true;
            }
            return false;
        }

        // Fall through to wildcard
        $wcDisallow = $rules['*']['disallow'] ?? [];
        $wcAllow    = $rules['*']['allow']    ?? [];

        return $this->pathListBlocksRoot($wcDisallow) && !$this->pathListAllowsRoot($wcAllow);
    }

    /**
     * @param string[] $paths
     */
    private function pathListBlocksRoot(array $paths): bool
    {
        return in_array('/', $paths, true) || in_array('/*', $paths, true);
    }

    /**
     * @param string[] $paths
     */
    private function pathListAllowsRoot(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path === '/' || $path === '/*' || $path === '') {
                return true;
            }
        }
        return false;
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
            if ($this->pathListBlocksRoot($rule['disallow']) && $this->pathListAllowsRoot($rule['allow'])) {
                $issues[] = sprintf(
                    'Agent "%s" has both Allow: / and Disallow: / — Allow wins, but the conflict is suspicious',
                    $agent
                );
            }
        }

        return $issues;
    }
}
