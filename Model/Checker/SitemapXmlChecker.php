<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;

/**
 * Validates sitemap.xml for AI crawler completeness.
 *
 * v3.1 changes:
 *  - FIX: the v3 "disproportion" warning compared sitemap URL count against
 *         active PRODUCTS only. A sitemap also contains the homepage, CMS pages
 *         and categories, so the delta was almost always large and produced a
 *         false WARN on healthy stores. Coverage is now computed against the
 *         full indexable surface (products + categories + CMS) and reported as
 *         INFO context only — it never changes the result status.
 *  - FIX: staleness no longer punishes a single old product. A legitimately
 *         unchanged product SHOULD keep an old <lastmod>; that is honest
 *         metadata. We now look at the NEWEST <lastmod> across the whole file —
 *         if nothing changed in a long time the generation cron may be broken.
 *         Individual old entries are INFO only.
 *  - NEW: detects foreign / non-sitemap elements inside <urlset> (e.g. a stray
 *         <script> injected by a theme/module). libxml parses these without
 *         error, so the old $xml === false validity check never caught them.
 *  - NEW: flags "placeholder" slugs (test2.html, product-name.html…) that give
 *         AI engines nothing to read. Behaviour is configurable:
 *           • mode = score  → warns once the configured threshold is reached
 *           • mode = ignore → reported in details only, never affects the score
 */
class SitemapXmlChecker extends AbstractChecker
{
    private const STALE_DAYS_NEWEST = 180; // whole-sitemap staleness (broken cron)
    private const MIN_URLS          = 5;

    /** Slugs that carry no semantic meaning for an AI engine. */
    private const PLACEHOLDER_SLUG_PATTERNS = [
        '/^test\d*$/i',
        '/^test[-_]/i',
        '/^product[-_]name$/i',
        '/^untitled/i',
        '/^new[-_]product/i',
        '/^[a-z]?\d+$/i', // bare numbers / single letter + number
    ];

    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
        private readonly Config $config,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'sitemap.xml — AI crawler discovery';
    }

    public function getCode(): string
    {
        return 'sitemap';
    }

    public function getWeight(): float
    {
        return 0.8;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);

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

        // Check for .gz variant (large catalogs)
        $hasGz = ($this->statusCode($foundUrl . '.gz') === 200);

        // XML validity (libxml is lenient — see detectForeignElements for the gap this leaves)
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
                ['url' => $foundUrl, 'type' => 'index', 'child_count' => count($m[0]), 'has_gz' => $hasGz]
            );
        }

        $urlCount = substr_count($body, '<loc>');

        // robots.txt sitemap directive — served from HttpCache when RobotsTxtChecker ran first
        [, $robotsBody] = $this->fetch($base . '/robots.txt');
        $inRobots = !empty($robotsBody) && stripos($robotsBody, 'sitemap:') !== false;

        // Structural integrity: foreign elements inside <urlset>
        $foreignElements = $this->detectForeignElements($body);

        // Placeholder slugs (config-driven handling)
        $placeholderSlugs = $this->detectPlaceholderSlugs($body);

        // Coverage vs the full indexable surface — INFO context only
        $indexable     = $this->countIndexableEntities($store);
        $coverageRatio = $indexable > 0 ? round($urlCount / $indexable, 2) : null;

        $storeId   = (int) $store->getId();
        $slugMode  = $this->config->getSitemapSlugMode($storeId);
        $slugLimit = $this->config->getSitemapSlugThreshold($storeId);

        $details = [
            'url'                  => $foundUrl,
            'url_count'            => $urlCount,
            'referenced_in_robots' => $inRobots,
            'has_gz'               => $hasGz,
            'indexable_entities'   => $indexable,
            'coverage_ratio'       => $coverageRatio,
            'placeholder_slugs'    => $placeholderSlugs,
            'placeholder_slug_mode' => $slugMode,
            'foreign_elements'     => $foreignElements,
        ];

        // ── FAIL: structural corruption (stray <script> etc. inside <urlset>) ──
        if (!empty($foreignElements)) {
            return $this->fail(
                sprintf(
                    'sitemap.xml contains %d non-sitemap element(s): %s',
                    count($foreignElements),
                    implode(', ', array_map(static fn ($e) => '<' . $e . '>', $foreignElements))
                ),
                'A theme block or module is injecting markup into the sitemap output. '
                . 'Find and remove it, then regenerate: bin/magento sitemap:generate',
                $details
            );
        }

        // ── WARN: soft, AEO-relevant issues ──
        $warnings = [];

        if ($urlCount < self::MIN_URLS) {
            $warnings[] = sprintf('only %d URLs — sitemap may be incomplete', $urlCount);
        }

        if (preg_match_all('/<lastmod>(.*?)<\/lastmod>/i', $body, $lastmodMatches)) {
            $timestamps = array_filter(array_map('strtotime', $lastmodMatches[1]));
            if (!empty($timestamps)) {
                $newestAge = (int) ((time() - max($timestamps)) / 86400);
                $oldestAge = (int) ((time() - min($timestamps)) / 86400);
                // INFO only — individual old entries are normal and not penalised.
                $details['newest_lastmod_days_ago'] = $newestAge;
                $details['oldest_lastmod_days_ago'] = $oldestAge;
                // WARN only if the NEWEST entry is old — i.e. NOTHING in the whole
                // sitemap has changed recently. Two distinct causes, so we name both:
                //   1) the sitemap generation cron isn't running, or
                //   2) the cron runs but product `updated_at` is frozen, so it just
                //      rewrites the same stale <lastmod> values (common on seeded/
                //      demo catalogs). Either way AI crawlers read the store as inactive.
                if ($newestAge > self::STALE_DAYS_NEWEST) {
                    $warnings[] = sprintf(
                        'newest <lastmod> is %d days old — either the sitemap cron is not '
                        . 'running, or products have not been updated and the cron is just '
                        . 'rewriting stale dates; AI crawlers may treat the store as inactive',
                        $newestAge
                    );
                }
            }
        }

        if (!$inRobots) {
            $warnings[] = 'not declared in robots.txt';
        }

        // Placeholder slugs only affect the score in "score" mode and once the
        // configured threshold is reached. In "ignore" mode they stay in
        // details but never warn.
        if ($slugMode === Config::SLUG_MODE_SCORE && count($placeholderSlugs) >= $slugLimit) {
            $sample = array_slice($placeholderSlugs, 0, 3);
            $warnings[] = sprintf(
                '%d placeholder slug(s) AI cannot interpret (e.g. %s)',
                count($placeholderSlugs),
                implode(', ', $sample)
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('sitemap.xml found (%d URLs) — %d issue(s)', $urlCount, count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('sitemap.xml — %d URLs, robots.txt referenced%s.', $urlCount, $hasGz ? ', .gz available' : ''),
            $details
        );
    }

    /**
     * Detects elements that are NOT part of the sitemap spec sitting directly
     * inside <urlset>. libxml parses these silently, so we scan the raw body.
     *
     * @return string[] unique foreign tag names (namespace prefixes stripped)
     */
    private function detectForeignElements(string $body): array
    {
        $allowed = ['url', 'loc', 'lastmod', 'changefreq', 'priority', 'urlset'];

        if (!preg_match('/<urlset[^>]*>(.*)<\/urlset>/is', $body, $m)) {
            return [];
        }

        // Remove every <url>...</url> block; remaining opening tags are foreign.
        $stripped = preg_replace('/<url>.*?<\/url>/is', '', $m[1]);

        preg_match_all('/<([a-zA-Z][\w.\-]*)[\s\/>]/', (string) $stripped, $tags);

        $foreign = [];
        foreach ($tags[1] as $tag) {
            $local = strtolower((string) preg_replace('/^.*:/', '', $tag)); // drop namespace prefix
            if (!in_array($local, $allowed, true) && !in_array($local, $foreign, true)) {
                $foreign[] = $local;
            }
        }

        return $foreign;
    }

    /**
     * Finds product/CMS slugs that convey no meaning to an AI engine.
     *
     * @return string[] de-duplicated sample of offending slugs (max 20)
     */
    private function detectPlaceholderSlugs(string $body): array
    {
        preg_match_all('/<loc>\s*(.*?)\s*<\/loc>/is', $body, $m);

        $bad = [];
        foreach ($m[1] as $url) {
            $path = parse_url(trim($url), PHP_URL_PATH) ?? '';
            $slug = (string) preg_replace('/\.html?$/i', '', basename(rtrim((string) $path, '/')));
            if ($slug === '' || $slug === '/') {
                continue; // homepage — fine
            }
            foreach (self::PLACEHOLDER_SLUG_PATTERNS as $pattern) {
                if (preg_match($pattern, $slug)) {
                    $bad[] = $slug;
                    break;
                }
            }
            if (count($bad) >= 20) {
                break;
            }
        }

        return array_values(array_unique($bad));
    }

    /**
     * Counts the full indexable surface a sitemap is expected to cover:
     * active products + active categories + active CMS pages.
     *
     * Used for INFO context (coverage_ratio) only — never to pass/fail.
     */
    private function countIndexableEntities(StoreInterface $store): int
    {
        $storeId = (int) $store->getId();
        $total   = 0;

        try {
            $products = $this->productCollectionFactory->create();
            $products->setStoreId($storeId)
                ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
            $total += $products->getSize();
        } catch (\Throwable) {
            // best-effort
        }

        try {
            $categories = $this->categoryCollectionFactory->create();
            $categories->setStore($storeId)
                ->addAttributeToFilter('is_active', 1);
            $total += $categories->getSize();
        } catch (\Throwable) {
            // best-effort
        }

        try {
            $cmsPages = $this->cmsPageCollectionFactory->create();
            $cmsPages->addFieldToFilter('is_active', 1);
            $total += $cmsPages->getSize();
        } catch (\Throwable) {
            // best-effort
        }

        return $total;
    }
}
