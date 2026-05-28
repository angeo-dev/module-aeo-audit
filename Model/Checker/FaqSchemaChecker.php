<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Detects FAQPage JSON-LD schema on the homepage or a sampled CMS page.
 *
 * FAQ schema dramatically increases AI citation rate (Google AI Mode, ChatGPT)
 * because question/answer pairs are the most directly extractable signal for
 * answer-style queries.
 */
class FaqSchemaChecker extends AbstractChecker
{
    public function getName(): string
    {
        return 'FAQPage schema — Q&A for AI answers';
    }

    public function getCode(): string
    {
        return 'faq_schema';
    }

    public function getWeight(): float
    {
        return 0.5;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-rich-data';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);
        $candidates = array_filter([
            $base,
            $this->urlSampler->getSampleCmsPageUrl($store),
        ]);

        $checked = [];
        foreach ($candidates as $url) {
            [$status, $html] = $this->fetch($url);
            $checked[] = ['url' => $url, 'http_status' => $status];
            if ($status !== 200 || empty($html)) {
                continue;
            }
            $schemas = $this->extractJsonLdSchemas($html);
            $faq     = $this->findSchemaByType($schemas, 'FAQPage');
            if ($faq !== null) {
                $questionCount = count($faq['mainEntity'] ?? []);
                return $this->pass(
                    sprintf('FAQPage schema found on %s — %d question(s).', $url, $questionCount),
                    ['url' => $url, 'question_count' => $questionCount]
                );
            }
        }

        return $this->warn(
            'No FAQPage schema found on homepage or sampled CMS page.',
            'Add FAQPage JSON-LD on FAQ pages — improves AI citation rate for question-style queries.',
            ['checked' => $checked]
        );
    }
}
