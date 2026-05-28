<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;

/**
 * Validates sitemap.xml for AI crawler completeness.
 *
 * v3 enhancements:
 *  - Checks sitemap.xml.gz (compressed variant) — required for large catalogs
 *  - Compares URL count vs active product count in catalog (warn on disproportion)
 *  - Removes duplicated robots.txt fetch (now via HttpCache)
 */
class SitemapXmlChecker extends AbstractChecker
{
    private const STALE_DAYS  = 90;
    private const MIN_URLS    = 5;
    private const DISPROPORTION_THRESHOLD = 0.3; // 30 % delta = warn

    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly ProductCollectionFactory $productCollectionFactory,
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
                ['url' => $foundUrl, 'type' => 'index', 'child_count' => count($m[0]), 'has_gz' => $hasGz]
            );
        }

        $urlCount = substr_count($body, '<loc>');

        // robots.txt sitemap directive — fetch from cache (already loaded by RobotsTxtChecker)
        [, $robotsBody] = $this->fetch($base . '/robots.txt');
        $inRobots = !empty($robotsBody) && stripos($robotsBody, 'sitemap:') !== false;

        // Catalog disproportion check
        $catalogProductCount = $this->countActiveProducts($store);
        $disproportion = null;
        $disproportionWarning = null;
        if ($catalogProductCount > 0 && $urlCount > 0) {
            $delta = abs($urlCount - $catalogProductCount) / $catalogProductCount;
            $disproportion = round($delta, 3);
            if ($delta > self::DISPROPORTION_THRESHOLD) {
                $disproportionWarning = sprintf(
                    'sitemap has %d URLs but catalog has %d active products (%.0f%% delta)',
                    $urlCount,
                    $catalogProductCount,
                    $delta * 100
                );
            }
        }

        $details = [
            'url'                  => $foundUrl,
            'url_count'            => $urlCount,
            'referenced_in_robots' => $inRobots,
            'has_gz'               => $hasGz,
            'active_products'      => $catalogProductCount,
            'disproportion'        => $disproportion,
        ];

        $warnings = [];

        if ($urlCount < self::MIN_URLS) {
            $warnings[] = sprintf('sitemap.xml has only %d URLs — may be incomplete', $urlCount);
        }

        if (preg_match('/<lastmod>(.*?)<\/lastmod>/i', $body, $lastmodMatch)) {
            $age = (int) ((time() - strtotime($lastmodMatch[1])) / 86400);
            $details['lastmod_days_ago'] = $age;
            if ($age > self::STALE_DAYS) {
                $warnings[] = sprintf('sitemap last modified %d days ago — may be stale', $age);
            }
        }

        if (!$inRobots) {
            $warnings[] = 'sitemap.xml not declared in robots.txt';
        }

        if ($disproportionWarning !== null) {
            $warnings[] = $disproportionWarning;
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

    private function countActiveProducts(StoreInterface $store): int
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId((int) $store->getId())
                ->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
            return $collection->getSize();
        } catch (\Throwable) {
            return 0;
        }
    }
}
