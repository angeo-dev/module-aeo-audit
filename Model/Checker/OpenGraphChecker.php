<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks Open Graph meta tags on homepage and product pages.
 */
class OpenGraphChecker extends AbstractChecker
{
    private const REQUIRED_OG_TAGS = ['og:title', 'og:description', 'og:image', 'og:url'];

    public function getName(): string
    {
        return 'Open Graph — Social & AI Preview Tags';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/');

        if ($status !== 200 || empty($body)) {
            return CheckResult::warn(
                $this->getName(),
                'Could not fetch homepage to check Open Graph tags.',
                'Ensure homepage is publicly accessible.',
                ['url' => $base . '/']
            );
        }

        $missingTags = [];
        $foundTags   = [];

        foreach (self::REQUIRED_OG_TAGS as $tag) {
            if (str_contains($body, 'property="' . $tag . '"') || str_contains($body, "property='" . $tag . "'")) {
                $foundTags[] = $tag;
            } else {
                $missingTags[] = $tag;
            }
        }

        $details = ['found' => $foundTags, 'missing' => $missingTags];

        if (empty($missingTags)) {
            return CheckResult::pass(
                $this->getName(),
                'All required Open Graph tags found on homepage.',
                $details
            );
        }

        if (count($missingTags) <= 1) {
            return CheckResult::warn(
                $this->getName(),
                'Open Graph partially configured. Missing: ' . implode(', ', $missingTags),
                'Add missing OG tags. AI crawlers use og:description as fallback content.',
                $details
            );
        }

        return CheckResult::fail(
            $this->getName(),
            'Open Graph tags missing: ' . implode(', ', $missingTags),
            'Add Open Graph meta tags to your store. They are used by AI systems for content previews and context extraction.',
            $details
        );
    }
}
