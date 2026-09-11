<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

/**
 * Minimal robots.txt parser shared by RobotsTxtChecker and WafRealityChecker.
 *
 * Extracted from RobotsTxtChecker in 4.0.0 so both checkers answer
 * "does robots.txt block bot X from root?" with identical logic — a
 * prerequisite for the WAF reality check, which compares the robots.txt
 * *declaration* against the *observed* edge behaviour.
 *
 * Scope is deliberately narrow: root-level allow/disallow resolution per
 * agent group. Full RFC 9309 path matching is out of scope for an audit
 * that only needs the "is this bot invited at all?" answer.
 *
 * @api
 * @since 4.0.0
 */
class RobotsTxtParser
{
    /**
     * Parse robots.txt content into per-agent rule groups.
     *
     * @return array<string, array{allow: string[], disallow: string[], crawl_delay: string|null}>
     */
    public function parse(string $content): array
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
     * Is the given bot blocked from the site root, considering explicit
     * per-bot groups (which take precedence) and the wildcard group?
     *
     * @param array<string, array{allow: string[], disallow: string[], crawl_delay: string|null}> $rules
     */
    public function isBotBlocked(string $robotsToken, array $rules): bool
    {
        $botLower = strtolower($robotsToken);

        // Explicit bot entry takes precedence over wildcard
        if (isset($rules[$botLower])) {
            $allow    = $rules[$botLower]['allow'];
            $disallow = $rules[$botLower]['disallow'];

            return $this->blocksRoot($disallow) && !$this->allowsRoot($allow);
        }

        // Fall through to wildcard
        $wcDisallow = $rules['*']['disallow'] ?? [];
        $wcAllow    = $rules['*']['allow']    ?? [];

        return $this->blocksRoot($wcDisallow) && !$this->allowsRoot($wcAllow);
    }

    /**
     * @param string[] $paths
     */
    public function blocksRoot(array $paths): bool
    {
        return in_array('/', $paths, true) || in_array('/*', $paths, true);
    }

    /**
     * @param string[] $paths
     */
    public function allowsRoot(array $paths): bool
    {
        foreach ($paths as $path) {
            if ($path === '/' || $path === '/*' || $path === '') {
                return true;
            }
        }
        return false;
    }
}
