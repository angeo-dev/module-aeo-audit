<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Validates Open Graph meta tags.
 *
 * Strategy:
 * 1. Check homepage — OG optional here (Magento default), WARN if missing
 * 2. Check first visible product page — OG required here, FAIL if missing
 *
 * This reflects real-world Magento config where OG is set up only for
 * product pages via Stores → Configuration → Catalog → Product → Social Sharing.
 */
class OpenGraphChecker extends AbstractChecker
{
    private const REQUIRED_TAGS    = ['og:title', 'og:description', 'og:image', 'og:url', 'og:type'];
    private const RECOMMENDED_TAGS = ['og:image:width', 'og:image:height', 'og:locale'];
    private const MIN_DESC_LENGTH  = 50;

    public function __construct(
        Curl $curl,
        private readonly CollectionFactory $productCollectionFactory,
    ) {
        parent::__construct($curl);
    }

    public function getName(): string  { return 'Open Graph — AI preview tags'; }
    public function getCode(): string  { return 'open_graph'; }
    public function getWeight(): float { return 0.7; }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);

        // ── 1. Homepage check (OG optional here) ────────────────────────────
        [$homeStatus, $homeHtml] = $this->fetch($base . '/');
        $homeTags = ($homeStatus === 200 && $homeHtml) ? $this->extractOgTags($homeHtml) : [];

        // ── 2. Product page check (OG required here) ─────────────────────────
        $productUrl = $this->getSampleProductUrl();

        if ($productUrl === null) {
            // No products — fall back to homepage-only check
            if (empty($homeTags)) {
                return $this->warn(
                    'No OG tags on homepage and no visible products found to verify product page OG.',
                    'Enable Open Graph via Stores → Configuration → Catalog → Product → Social Sharing.',
                    ['homepage_tags' => [], 'product_url' => null]
                );
            }
            return $this->buildHomepageResult($homeTags);
        }

        [$productStatus, $productHtml] = $this->fetch($productUrl);

        if ($productStatus !== 200 || empty($productHtml)) {
            return $this->warn(
                'Could not fetch product page for OG check (HTTP ' . ($productStatus ?: 'error') . ').',
                'Ensure the store URL is publicly accessible.',
                ['product_url' => $productUrl]
            );
        }

        $productTags = $this->extractOgTags($productHtml);
        $missing     = array_values(array_diff(self::REQUIRED_TAGS, array_keys($productTags)));

        $details = [
            'product_url'     => $productUrl,
            'product_tags'    => array_keys($productTags),
            'homepage_tags'   => array_keys($homeTags),
            'missing'         => $missing,
            'missing_rec'     => array_values(array_diff(self::RECOMMENDED_TAGS, array_keys($productTags))),
            'homepage_has_og' => !empty($homeTags),
        ];

        // No OG on product page at all — this is the real problem
        if (empty($productTags)) {
            return $this->fail(
                sprintf('No Open Graph tags found on product page: %s', $productUrl),
                'Enable Open Graph via Stores → Configuration → Catalog → Product → Social Sharing. ' .
                'AI crawlers use og:image and og:description to generate product cards.',
                $details
            );
        }

        // Missing critical tags on product page
        if (!empty($missing)) {
            return $this->warn(
                sprintf('OG tags partially configured on product page. Missing: %s', implode(', ', $missing)),
                'Add missing OG tags — og:description is used by AI as fallback product content.',
                $details
            );
        }

        // Quality: og:description length
        $desc = $productTags['og:description'] ?? '';
        if (strlen($desc) < self::MIN_DESC_LENGTH) {
            return $this->warn(
                sprintf(
                    'All required OG tags present on product page, but og:description is short (%d chars).',
                    strlen($desc)
                ),
                'Aim for 120–160 character og:description for best AI citation quality.',
                $details
            );
        }

        // Recommended tags missing
        if (!empty($details['missing_rec'])) {
            return $this->warn(
                'All required OG tags present on product page. Add recommended: ' .
                implode(', ', $details['missing_rec']),
                'og:image dimensions help AI engines generate better product cards.',
                $details
            );
        }

        $homepageNote = empty($homeTags) ? ' (homepage has no OG — normal for Magento)' : '';

        return $this->pass(
            sprintf(
                'All OG tags present on product page (%d tags found)%s.',
                count($productTags),
                $homepageNote
            ),
            $details
        );
    }

    // ── Homepage-only fallback (no products) ─────────────────────────────────

    private function buildHomepageResult(array $tags): CheckResult
    {
        $missing = array_values(array_diff(self::REQUIRED_TAGS, array_keys($tags)));
        $details = [
            'found'       => array_keys($tags),
            'missing'     => $missing,
            'missing_rec' => array_values(array_diff(self::RECOMMENDED_TAGS, array_keys($tags))),
            'product_url' => null,
        ];

        if (!empty($missing)) {
            return $this->warn(
                sprintf('OG tags partially configured on homepage. Missing: %s', implode(', ', $missing)),
                'Add missing OG tags for best AI preview coverage.',
                $details
            );
        }

        return $this->pass(
            sprintf('All OG tags present on homepage (%d tags found).', count($tags)),
            $details
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractOgTags(string $html): array
    {
        $tags = [];
        foreach ([
                     '/<meta[^>]+property=["\']([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i',
                     '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']([^"\']+)["\'][^>]*>/i',
                 ] as $i => $pattern) {
            preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $prop    = $i === 0 ? $m[1] : $m[2];
                $content = $i === 0 ? $m[2] : $m[1];
                if (str_starts_with($prop, 'og:')) {
                    $tags[$prop] = $content;
                }
            }
        }
        return $tags;
    }

    private function getSampleProductUrl(): ?string
    {
        $collection = $this->productCollectionFactory->create();
        $collection
            ->addAttributeToSelect(['url_key', 'status', 'visibility'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', [
                'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
            ])
            ->addUrlRewrite()
            ->setPageSize(1)
            ->getSelect()->orderRand();

        $product = $collection->getFirstItem();

        if (!$product->getId()) {
            return null;
        }

        try {
            return $product->getProductUrl();
        } catch (\Exception) {
            return null;
        }
    }
}
