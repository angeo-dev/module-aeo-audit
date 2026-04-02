<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks for FAQPage JSON-LD schema on the homepage.
 * FAQ schema significantly increases chances of being cited in AI answers.
 */
class FaqSchemaChecker extends AbstractChecker
{
    public function getName(): string
    {
        return 'FAQPage Schema — AI Answer Eligibility';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/');

        if ($status !== 200 || empty($body)) {
            return CheckResult::warn(
                $this->getName(),
                'Could not fetch homepage to check FAQPage schema.',
                'Ensure your homepage is publicly accessible.',
                ['url' => $base . '/']
            );
        }

        $hasFaqSchema      = $this->hasSchemaType($body, 'FAQPage');
        $hasHowToSchema    = $this->hasSchemaType($body, 'HowTo');
        $hasArticleSchema  = $this->hasSchemaType($body, 'Article');

        $details = [
            'url'           => $base . '/',
            'FAQPage'       => $hasFaqSchema ? 'yes' : 'no',
            'HowTo'         => $hasHowToSchema ? 'yes' : 'no',
            'Article'       => $hasArticleSchema ? 'yes' : 'no',
        ];

        if ($hasFaqSchema) {
            return CheckResult::pass(
                $this->getName(),
                'FAQPage schema found — your content is eligible for AI answer boxes.',
                $details
            );
        }

        if ($hasHowToSchema || $hasArticleSchema) {
            return CheckResult::warn(
                $this->getName(),
                'No FAQPage schema on homepage, but other rich schema types found.',
                'Add FAQPage JSON-LD with common customer questions (shipping, returns, sizing) ' .
                'to increase AI citation probability by up to 3x.',
                $details
            );
        }

        return CheckResult::fail(
            $this->getName(),
            'No FAQPage or other answer-eligible schema found on homepage.',
            'Add a FAQPage JSON-LD block to your homepage with 3–5 common questions. ' .
            'AI engines like ChatGPT and Gemini use this schema to source direct answers.',
            $details
        );
    }

    private function hasSchemaType(string $html, string $type): bool
    {
        return str_contains($html, '"@type":"' . $type . '"')
            || str_contains($html, '"@type": "' . $type . '"');
    }
}
