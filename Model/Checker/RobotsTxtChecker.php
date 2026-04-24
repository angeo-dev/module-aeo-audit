<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Deep robots.txt validation for AI bot access.
 *
 * Improvements over v1:
 * - Real robots.txt parser (not string search)
 * - Handles wildcard User-agent: * with per-bot override logic
 * - Detects partial blocks (some bots OK, some not)
 * - Checks OAI-SearchBot separately (required for ChatGPT Shopping)
 * - 10 bots including 2026 additions (Applebot, cohere-ai)
 */
class RobotsTxtChecker extends AbstractChecker
{
    /**
     * Canonical AI bot list — April 2026.
     * Format: ua => [label, critical]
     * critical=true means blocking this bot is a FAIL, not just WARN
     */
    private const AI_BOTS = [
        'GPTBot'          => ['label' => 'OpenAI crawler',                   'critical' => true],
        'OAI-SearchBot'   => ['label' => 'OpenAI Shopping indexer',          'critical' => true],
        'ChatGPT-User'    => ['label' => 'ChatGPT browsing agent',           'critical' => false],
        'ClaudeBot'       => ['label' => 'Anthropic / Claude',               'critical' => false],
        'anthropic-ai'    => ['label' => 'Anthropic alt UA',                 'critical' => false],
        'PerplexityBot'   => ['label' => 'Perplexity AI',                    'critical' => false],
        'Google-Extended' => ['label' => 'Google AI Overviews / Gemini',     'critical' => true],
        'Applebot'        => ['label' => 'Apple AI / Siri',                  'critical' => false],
        'cohere-ai'       => ['label' => 'Cohere',                           'critical' => false],
        'Amazonbot'       => ['label' => 'Amazon Alexa AI',                  'critical' => false],
    ];

    public function getName(): string  { return 'robots.txt — AI bot access'; }
    public function getCode(): string  { return 'robots_txt'; }
    public function getWeight(): float { return 1.0; }
    public function getFixCommand(): string
    {
        return 'composer require angeo/module-robots-txt-aeo';
    }


    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/robots.txt');

        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'robots.txt not found or returned HTTP ' . ($status ?: 'error') . '.',
                'Create robots.txt at your store root and explicitly allow AI crawlers.',
                ['url' => $base . '/robots.txt', 'http_status' => $status]
            );
        }

        $rules         = $this->parseRobotsTxt($body);
        $blocked       = [];
        $blockedCritical = [];
        $allowed       = [];

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

        // Check sitemap directive presence (bonus check while we have robots.txt)
        $hasSitemap = (bool) preg_match('/^sitemap:/im', $body);

        $details = [
            'url'            => $base . '/robots.txt',
            'blocked'        => $blocked,
            'allowed'        => $allowed,
            'sitemap_listed' => $hasSitemap,
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

        if (!empty($blocked)) {
            return $this->warn(
                sprintf('%d AI bot(s) blocked: %s', count($blocked), implode(', ', $blocked)),
                sprintf(
                    "Add explicit Allow rules:\n%s",
                    implode("\n\n", array_map(
                        static fn($b) => "User-agent: $b\nAllow: /",
                        $blocked
                    ))
                ),
                $details
            );
        }

        if (!$hasSitemap) {
            return $this->warn(
                sprintf('All %d AI bots allowed, but sitemap not declared in robots.txt.', count(self::AI_BOTS)),
                'Add "Sitemap: ' . $base . '/sitemap.xml" to robots.txt.',
                $details
            );
        }

        return $this->pass(
            sprintf('All %d AI bots permitted. Sitemap declared.', count(self::AI_BOTS)),
            $details
        );
    }

    /**
     * Parse robots.txt into structured rules.
     *
     * @return array<string, array{allow: string[], disallow: string[]}>
     */
    private function parseRobotsTxt(string $content): array
    {
        $rules          = [];
        $currentAgents  = [];

        foreach (explode("\n", $content) as $rawLine) {
            $line = trim(explode('#', $rawLine)[0]); // strip inline comments

            if ($line === '') {
                $currentAgents = []; // blank line resets agent block
                continue;
            }

            if (stripos($line, 'user-agent:') === 0) {
                $agent = strtolower(trim(substr($line, 11)));
                $currentAgents[] = $agent;
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
            }
        }

        return $rules;
    }

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
            // Explicit block-free entry = allowed regardless of wildcard
            return false;
        }

        // Fall through to wildcard
        $wcDisallow = $rules['*']['disallow'] ?? [];
        $wcAllow    = $rules['*']['allow']    ?? [];

        return $this->pathListBlocksRoot($wcDisallow) && !$this->pathListAllowsRoot($wcAllow);
    }

    private function pathListBlocksRoot(array $paths): bool
    {
        return in_array('/', $paths, true) || in_array('/*', $paths, true);
    }

    private function pathListAllowsRoot(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path === '/' || $path === '/*' || $path === '') {
                return true;
            }
        }
        return false;
    }
}
