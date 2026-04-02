<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks sitemap.xml presence and basic quality.
 */
class SitemapXmlChecker extends AbstractChecker
{
    private const MIN_URL_COUNT = 5;

    public function getName(): string
    {
        return 'sitemap.xml — Search Engine Discovery';
    }

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
            return CheckResult::fail(
                $this->getName(),
                'sitemap.xml not found in standard locations.',
                'Enable Magento sitemap under Marketing → SEO & Search → Site Map, then submit in robots.txt and Google Search Console.'
            );
        }

        $urlCount = substr_count($body, '<loc>');
        $details  = ['sitemap_url' => $foundUrl, 'url_count' => $urlCount];

        [, $robotsBody] = $this->fetch($base . '/robots.txt');
        $sitemapInRobots = !empty($robotsBody) && str_contains(strtolower($robotsBody), 'sitemap:');
        $details['referenced_in_robots'] = $sitemapInRobots ? 'yes' : 'no';

        if ($urlCount < self::MIN_URL_COUNT) {
            return CheckResult::warn(
                $this->getName(),
                sprintf('sitemap.xml found but only %d URLs — may be incomplete.', $urlCount),
                'Ensure all product, category, and CMS pages are included.',
                $details
            );
        }

        if (!$sitemapInRobots) {
            return CheckResult::warn(
                $this->getName(),
                sprintf('sitemap.xml OK (%d URLs) but not referenced in robots.txt.', $urlCount),
                'Add "Sitemap: ' . $foundUrl . '" to your robots.txt.',
                $details
            );
        }

        return CheckResult::pass(
            $this->getName(),
            sprintf('sitemap.xml found (%d URLs) and referenced in robots.txt.', $urlCount),
            $details
        );
    }
}
