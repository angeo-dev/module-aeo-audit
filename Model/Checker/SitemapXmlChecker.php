<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Validates sitemap.xml for AI crawler completeness.
 *
 * Improvements over v1:
 * - XML validity check (not just URL count)
 * - Detects sitemap index vs single sitemap
 * - Checks lastmod freshness (stale > 90 days = warn)
 * - Checks sitemap referenced in robots.txt (kept from v1, good check)
 */
class SitemapXmlChecker extends AbstractChecker
{
    private const STALE_DAYS   = 90;
    private const MIN_URLS     = 5;

    public function getName(): string  { return 'sitemap.xml — AI crawler discovery'; }
    public function getCode(): string  { return 'sitemap'; }
    public function getWeight(): float { return 0.8; }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);

        $candidates = [
            $base . '/sitemap.xml',
            $base . '/sitemap_index.xml',
            $base . '/pub/sitemap.xml',
        ];

        $foundUrl = null;
        $body     = '';
        foreach ($candidates as $candidate) {
            [$status, $content] = $this->fetch($candidate);
            if ($status === 200 && !empty($content)) {
                $foundUrl = $candidate;
                $body     = $content;
                break;
            }
        }

        if ($foundUrl === null) {
            return $this->fail(
                'sitemap.xml not found in standard locations.',
                'Enable Magento sitemap: Marketing → SEO & Search → Site Map, then add Sitemap directive to robots.txt.'
            );
        }

        // XML validity
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($body);
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            return $this->fail(
                'sitemap.xml found but is not valid XML.',
                'Regenerate with: bin/magento sitemap:generate',
                ['url' => $foundUrl]
            );
        }

        // Sitemap index?
        if (stripos($body, '<sitemapindex') !== false) {
            preg_match_all('/<sitemap>/i', $body, $m);
            return $this->pass(
                sprintf('Sitemap index found with %d child sitemaps.', count($m[0])),
                ['url' => $foundUrl, 'type' => 'index', 'child_count' => count($m[0])]
            );
        }

        $urlCount = substr_count($body, '<loc>');

        // Check robots.txt reference
        [, $robotsBody] = $this->fetch($base . '/robots.txt');
        $inRobots = !empty($robotsBody) && stripos($robotsBody, 'sitemap:') !== false;

        $details = [
            'url'                 => $foundUrl,
            'url_count'           => $urlCount,
            'referenced_in_robots' => $inRobots,
        ];

        if ($urlCount < self::MIN_URLS) {
            return $this->warn(
                sprintf('sitemap.xml found but only %d URLs — may be incomplete.', $urlCount),
                'Ensure all products, categories, and CMS pages are included.',
                $details
            );
        }

        // Stale lastmod check
        if (preg_match('/<lastmod>(.*?)<\/lastmod>/i', $body, $lastmodMatch)) {
            $age = (int) ((time() - strtotime($lastmodMatch[1])) / 86400);
            $details['lastmod_days_ago'] = $age;
            if ($age > self::STALE_DAYS) {
                return $this->warn(
                    sprintf('sitemap.xml has %d URLs but last modified %d days ago — may be stale.', $urlCount, $age),
                    'Schedule sitemap regeneration via cron: bin/magento cron:run --group=index',
                    $details
                );
            }
        }

        if (!$inRobots) {
            return $this->warn(
                sprintf('sitemap.xml OK (%d URLs) but not declared in robots.txt.', $urlCount),
                sprintf('Add "Sitemap: %s" to your robots.txt.', $foundUrl),
                $details
            );
        }

        return $this->pass(
            sprintf('sitemap.xml found — %d URLs, referenced in robots.txt.', $urlCount),
            $details
        );
    }
}
