<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Cms\Model\PageFactory as CmsPageFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Centralised URL sampling for AEO checkers.
 *
 * Multiple checkers need representative URLs from a store: a random product,
 * a category, a CMS page, the homepage. Before v3 this was duplicated across
 * ProductSchemaChecker, OpenGraphChecker (partially), and ad-hoc fallbacks.
 *
 * Samples are memoised per (store + type) for the lifetime of the service —
 * within a single audit run all checkers see the same sample product, which
 * makes results consistent across the report.
 *
 * @api
 * @since 3.0.0
 */
class StoreUrlSampler
{
    /** Random picks to try before settling for an empty category. */
    private const CATEGORY_SAMPLE_ATTEMPTS = 5;

    /** @var array<string, ?string> */
    private array $productUrlCache = [];

    /** @var array<string, ?string> */
    private array $categoryUrlCache = [];

    /** @var array<string, ?string> */
    private array $cmsUrlCache = [];

    public function __construct(
        private readonly StoreManagerInterface          $storeManager,
        private readonly ProductCollectionFactory       $productCollectionFactory,
        private readonly CategoryCollectionFactory      $categoryCollectionFactory,
        private readonly CmsPageCollectionFactory       $cmsPageCollectionFactory,
    ) {
    }

    /**
     * Returns store's secure base URL with no trailing slash.
     *
     * Falls back to the unsecured base URL only when no secure URL is
     * configured for the store.
     */
    public function getBaseUrl(StoreInterface $store): string
    {
        $url = $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true)
            ?: $store->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        return rtrim((string) $url, '/');
    }

    /**
     * Returns a random visible product URL for the store. Memoised per store.
     *
     * @return string|null Null if no visible enabled product exists.
     */
    public function getSampleProductUrl(StoreInterface $store): ?string
    {
        $key = (string) $store->getId();
        if (array_key_exists($key, $this->productUrlCache)) {
            return $this->productUrlCache[$key];
        }

        $this->productUrlCache[$key] = $this->resolveProductUrl($store);
        return $this->productUrlCache[$key];
    }

    /**
     * Returns a random visible category URL (not the root). Memoised.
     */
    public function getSampleCategoryUrl(StoreInterface $store): ?string
    {
        $key = (string) $store->getId();
        if (array_key_exists($key, $this->categoryUrlCache)) {
            return $this->categoryUrlCache[$key];
        }

        $this->categoryUrlCache[$key] = $this->resolveCategoryUrl($store);
        return $this->categoryUrlCache[$key];
    }

    /**
     * Returns a CMS page URL — prefers a page with FAQ-like content where
     * detectable (identifier or title contains 'faq'), otherwise any active
     * non-home CMS page. Memoised.
     */
    public function getSampleCmsPageUrl(StoreInterface $store): ?string
    {
        $key = (string) $store->getId();
        if (array_key_exists($key, $this->cmsUrlCache)) {
            return $this->cmsUrlCache[$key];
        }

        $this->cmsUrlCache[$key] = $this->resolveCmsUrl($store);
        return $this->cmsUrlCache[$key];
    }

    /**
     * Reset internal caches — useful when the same service instance is
     * reused across multiple audit runs (long-lived processes, tests).
     */
    public function reset(): void
    {
        $this->productUrlCache = [];
        $this->categoryUrlCache = [];
        $this->cmsUrlCache = [];
    }

    private function resolveProductUrl(StoreInterface $store): ?string
    {
        try {
            $collection = $this->productCollectionFactory->create();
            $collection
                ->setStoreId((int) $store->getId())
                ->addAttributeToSelect(['url_key', 'status', 'visibility'])
                ->addAttributeToFilter('status', ['eq' => ProductStatus::STATUS_ENABLED])
                ->addAttributeToFilter('visibility', [
                    'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
                ])
                ->addUrlRewrite()
                ->setPageSize(1);
            // Random offset instead of ORDER BY RAND(): RAND() forces a full
            // scan + filesort on large catalogs; COUNT + LIMIT/OFFSET is cheap.
            $this->applyRandomPage($collection);

            $product = $collection->getFirstItem();
            if (!$product->getId()) {
                return null;
            }
            return (string) $product->getProductUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Jump the collection to a random page (page size 1) so getFirstItem()
     * returns a pseudo-random row without ORDER BY RAND().
     *
     * @param \Magento\Framework\Data\Collection\AbstractDb $collection
     */
    private function applyRandomPage($collection): void
    {
        $size = (int) $collection->getSize();
        if ($size > 1) {
            // random_int is not used for security purposes here — sampling only.
            $collection->setCurPage(random_int(1, $size));
        }
    }

    private function resolveCategoryUrl(StoreInterface $store): ?string
    {
        // A random category can be empty, or belong to another store's root
        // tree. An empty listing correctly has no ItemList, so sampling one
        // produced a false "No ItemList / CollectionPage" finding. Stay inside
        // this store's root and prefer a category that lists products.
        $fallback = null;

        try {
            $rootId = method_exists($store, 'getRootCategoryId') ? (int) $store->getRootCategoryId() : 0;

            for ($attempt = 0; $attempt < self::CATEGORY_SAMPLE_ATTEMPTS; $attempt++) {
                $collection = $this->categoryCollectionFactory->create();
                $collection->setStoreId((int) $store->getId())
                    ->addAttributeToSelect(['name', 'url_key', 'is_active'])
                    ->addAttributeToFilter('is_active', ['eq' => 1])
                    ->addAttributeToFilter('level', ['gt' => 1])
                    ->setPageSize(1);
                if ($rootId > 0) {
                    $collection->addFieldToFilter('path', ['like' => '1/' . $rootId . '/%']);
                }
                if (method_exists($collection, 'setLoadProductCount')) {
                    $collection->setLoadProductCount(true);
                }
                $this->applyRandomPage($collection);

                /** @var CategoryInterface|false $category */
                $category = $collection->getFirstItem();
                if (!$category || !$category->getId() || !method_exists($category, 'getUrl')) {
                    return $fallback;
                }

                $url = (string) $category->getUrl();
                if ($url === '') {
                    continue;
                }

                $count = $category->getData('product_count');
                if ($count === null || (int) $count > 0) {
                    return $url;
                }

                $fallback ??= $url;
            }
        } catch (\Throwable) {
            return $fallback;
        }

        return $fallback;
    }

    private function resolveCmsUrl(StoreInterface $store): ?string
    {
        try {
            $collection = $this->cmsPageCollectionFactory->create();
            $collection->addStoreFilter((int) $store->getId())
                ->addFieldToFilter('is_active', 1)
                ->addFieldToFilter('identifier', ['neq' => 'home']);

            // Prefer FAQ-like pages
            $faqCollection = clone $collection;
            $faqCollection->addFieldToFilter(
                ['identifier', 'title'],
                [
                    ['like' => '%faq%'],
                    ['like' => '%faq%'],
                ]
            );
            $faqCollection->setPageSize(1);
            $page = $faqCollection->getFirstItem();

            if (!$page->getId()) {
                $collection->setPageSize(1);
                $page = $collection->getFirstItem();
            }
            if (!$page->getId()) {
                return null;
            }

            return $this->getBaseUrl($store) . '/' . ltrim((string) $page->getIdentifier(), '/');
        } catch (\Throwable) {
            return null;
        }
    }
}
