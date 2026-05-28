<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates Organization (or OnlineStore) JSON-LD on the homepage.
 *
 * 71% of pages cited by ChatGPT carry structured data; Organization on
 * homepage is the second-most-important schema after Product because it
 * establishes the brand as a knowledge-graph entity. Without it, AI knows
 * *what* you sell but not *who* you are — leading to disambiguation failures
 * in Claude / Perplexity.
 *
 * Accepted types: Organization, OnlineStore, LocalBusiness, Store, Corporation.
 *
 * @since 3.0.0
 */
class OrganizationSchemaChecker extends AbstractChecker
{
    private const ACCEPTED_TYPES = [
        'Organization',
        'OnlineStore',
        'OnlineBusiness',
        'LocalBusiness',
        'Store',
        'Corporation',
    ];

    private const REQUIRED_FIELDS    = ['name', 'url'];
    private const RECOMMENDED_FIELDS = ['logo', 'description', 'sameAs', 'contactPoint'];

    public function getName(): string
    {
        return 'Organization schema — brand entity';
    }

    public function getCode(): string
    {
        return 'organization_schema';
    }

    public function getWeight(): float
    {
        return 0.8;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);
        [$status, $html] = $this->fetch($base);

        if ($status !== 200 || empty($html)) {
            return $this->warn(
                'Could not fetch homepage (HTTP ' . ($status ?: 'error') . ').',
                'Ensure the store homepage is publicly accessible.',
                ['url' => $base]
            );
        }

        $schemas = $this->extractJsonLdSchemas($html);
        $org     = null;
        $orgType = null;
        foreach (self::ACCEPTED_TYPES as $type) {
            $found = $this->findSchemaByType($schemas, $type);
            if ($found !== null) {
                $org     = $found;
                $orgType = $type;
                break;
            }
        }

        if ($org === null) {
            return $this->fail(
                'No Organization / OnlineStore schema found on homepage.',
                'Add @type:Organization JSON-LD with name, url, logo, sameAs (social/Wikidata).'
                    . ' Establishes your brand as a knowledge-graph entity.',
                ['url' => $base]
            );
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($org[$field])) {
                $missing[] = $field;
            }
        }

        $missingRecommended = [];
        foreach (self::RECOMMENDED_FIELDS as $field) {
            if (empty($org[$field])) {
                $missingRecommended[] = $field;
            }
        }

        $sameAsCount = 0;
        if (!empty($org['sameAs'])) {
            $sameAsCount = is_array($org['sameAs']) ? count($org['sameAs']) : 1;
        }

        $details = [
            'url'                 => $base,
            'type'                => $orgType,
            'missing_required'    => $missing,
            'missing_recommended' => $missingRecommended,
            'sameAs_count'        => $sameAsCount,
            'has_logo'            => !empty($org['logo']),
            'has_contact_point'   => !empty($org['contactPoint']),
        ];

        if (!empty($missing)) {
            return $this->fail(
                sprintf('%s schema missing required field(s): %s', $orgType, implode(', ', $missing)),
                'Add missing fields. name and url are mandatory per Schema.org.',
                $details
            );
        }

        $warnings = [];
        if (!empty($missingRecommended)) {
            $warnings[] = sprintf('Missing recommended: %s', implode(', ', $missingRecommended));
        }
        if ($sameAsCount < 2) {
            $warnings[] = sprintf(
                'sameAs has %d link(s) — add 2+ (social + Wikidata) to strengthen entity disambiguation',
                $sameAsCount
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('%s schema valid but %d improvement(s)', $orgType, count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('%s schema complete — name, url, logo, %d sameAs link(s).', $orgType, $sameAsCount),
            $details
        );
    }
}
