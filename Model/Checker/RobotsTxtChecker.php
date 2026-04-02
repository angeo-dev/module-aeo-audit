<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks robots.txt for AI bot permissions.
 *
 * Required bots: GPTBot, ClaudeBot, PerplexityBot, anthropic-ai, Google-Extended
 */
class RobotsTxtChecker extends AbstractChecker
{
    private const AI_BOTS = [
        'GPTBot',
        'ClaudeBot',
        'PerplexityBot',
        'anthropic-ai',
        'Google-Extended',
        'OAI-SearchBot',
        'Applebot-Extended',
    ];

    public function getName(): string
    {
        return 'robots.txt — AI Bot Access';
    }

    public function check(string $baseUrl): CheckResult
    {
        $url = $this->normalizeBase($baseUrl) . '/robots.txt';
        [$status, $body] = $this->fetch($url);

        if ($status !== 200 || empty($body)) {
            return CheckResult::fail(
                $this->getName(),
                'robots.txt not found or empty.',
                'Create a robots.txt at your store root and explicitly allow AI crawlers.'
            );
        }

        $blockedBots  = [];
        $allowedBots  = [];
        $lines        = array_map('trim', explode("\n", strtolower($body)));

        foreach (self::AI_BOTS as $bot) {
            $botLower = strtolower($bot);

            // Find the User-agent block for this bot
            $isDisallowed = $this->isBotDisallowed($lines, $botLower);

            if ($isDisallowed) {
                $blockedBots[] = $bot;
            } else {
                $allowedBots[] = $bot;
            }
        }

        if (!empty($blockedBots)) {
            return CheckResult::warn(
                $this->getName(),
                sprintf('robots.txt blocks %d AI bot(s): %s', count($blockedBots), implode(', ', $blockedBots)),
                sprintf(
                    "Add to robots.txt:\n%s",
                    implode("\n", array_map(
                        fn($b) => "User-agent: $b\nAllow: /",
                        $blockedBots
                    ))
                ),
                ['blocked' => $blockedBots, 'allowed' => $allowedBots]
            );
        }

        return CheckResult::pass(
            $this->getName(),
            sprintf('All %d AI bots are permitted in robots.txt.', count(self::AI_BOTS)),
            ['allowed' => $allowedBots]
        );
    }

    private function isBotDisallowed(array $lines, string $botLower): bool
    {
        $inBotBlock     = false;
        $inWildcard     = false;
        $wildcardDisallowsAll = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'user-agent:')) {
                $agent    = trim(substr($line, strlen('user-agent:')));
                $inBotBlock = ($agent === $botLower);
                $inWildcard = ($agent === '*');
                continue;
            }

            if ($inWildcard && str_starts_with($line, 'disallow: /')) {
                $wildcardDisallowsAll = true;
            }

            if ($inBotBlock && str_starts_with($line, 'disallow: /')) {
                return true;
            }
        }

        return $wildcardDisallowsAll && !$this->hasBotExplicitAllow($lines, $botLower);
    }

    private function hasBotExplicitAllow(array $lines, string $botLower): bool
    {
        $inBotBlock = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'user-agent:')) {
                $inBotBlock = (trim(substr($line, strlen('user-agent:'))) === $botLower);
                continue;
            }
            if ($inBotBlock && str_starts_with($line, 'allow: /')) {
                return true;
            }
        }
        return false;
    }
}
