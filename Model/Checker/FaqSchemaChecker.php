<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as CmsPageCollectionFactory;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Checks FAQPage schema on homepage and CMS FAQ pages.
 *
 * Improvements over v1:
 * - Looks beyond homepage — checks CMS pages with "faq" in URL key
 * - HowTo / Article schema treated as WARN not FAIL (present but not optimal)
 * - Reports which pages have FAQ schema, not just homepage
 */
class FaqSchemaChecker extends AbstractChecker
{
    public function __construct(
        Curl $curl,
        private readonly CmsPageCollectionFactory $cmsPageCollectionFactory,
    ) {
        parent::__construct($curl);
    }

    public function getName(): string  { return 'FAQPage schema — AI answer eligibility'; }
    public function getCode(): string  { return 'faq_schema'; }
    public function getWeight(): float { return 0.5; }
    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }


    public function check(string $baseUrl): CheckResult
    {
        $base  = $this->normalizeBase($baseUrl);
        $pages = [$base . '/'];

        // Find CMS pages that look like FAQ pages
        $collection = $this->cmsPageCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('identifier', ['like' => '%faq%']);
        $collection->setPageSize(3);

        foreach ($collection as $page) {
            $pages[] = $base . '/' . $page->getIdentifier();
        }

        $foundOn        = [];
        $hasRelatedSchema = false;

        foreach ($pages as $url) {
            [,$html] = $this->fetch($url);
            if (empty($html)) {
                continue;
            }
            $schemas = $this->extractJsonLdSchemas($html);
            if ($this->findSchemaByType($schemas, 'FAQPage') !== null) {
                $foundOn[] = $url;
            }
            if (
                !$hasRelatedSchema &&
                ($this->findSchemaByType($schemas, 'HowTo') !== null
                    || $this->findSchemaByType($schemas, 'Article') !== null)
            ) {
                $hasRelatedSchema = true;
            }
        }

        $details = ['checked_pages' => $pages];

        if (!empty($foundOn)) {
            $details['schema_found_on'] = $foundOn;
            return $this->pass(
                sprintf('FAQPage schema found on %d page(s): %s', count($foundOn), implode(', ', $foundOn)),
                $details
            );
        }

        if ($hasRelatedSchema) {
            return $this->warn(
                'No FAQPage schema found but other answer-eligible schema detected (HowTo/Article).',
                'Add FAQPage JSON-LD with 3–5 common customer questions to increase AI citation probability.',
                $details
            );
        }

        return $this->warn(
            sprintf('No FAQPage schema found on %d checked page(s).', count($pages)),
            'Add FAQPage JSON-LD to your FAQ or homepage. AI engines use this to source direct answers.',
            $details
        );
    }
}
