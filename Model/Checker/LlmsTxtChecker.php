<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Checks for llms.txt presence and basic quality.
 */
class LlmsTxtChecker extends AbstractChecker
{
    private const MIN_CONTENT_LENGTH = 100;
    private const RECOMMENDED_SECTIONS = ['## About', '## Products', '## Categories', '## CMS'];

    public function getName(): string
    {
        return 'llms.txt — AI Content Map';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        [$status, $body] = $this->fetch($base . '/llms.txt');

        if ($status !== 200 || empty($body)) {
            return CheckResult::fail(
                $this->getName(),
                'llms.txt not found.',
                'Install angeo/module-llms-txt and generate your llms.txt file. ' .
                'Place it at your store root (/llms.txt).'
            );
        }

        $issues  = [];
        $details = ['url' => $base . '/llms.txt', 'size_bytes' => strlen($body)];

        if (strlen($body) < self::MIN_CONTENT_LENGTH) {
            $issues[] = sprintf('File is very small (%d bytes) — may be a stub.', strlen($body));
        }

        $missingSections = [];
        foreach (self::RECOMMENDED_SECTIONS as $section) {
            if (!str_contains($body, $section)) {
                $missingSections[] = $section;
            }
        }

        if (!empty($missingSections)) {
            $issues[] = 'Missing recommended sections: ' . implode(', ', $missingSections);
        }

        // Check llms-full.txt bonus
        [$fullStatus] = $this->fetch($base . '/llms-full.txt');
        $details['llms_full_txt'] = ($fullStatus === 200) ? 'present' : 'absent';

        if (!empty($issues)) {
            return CheckResult::warn(
                $this->getName(),
                'llms.txt found but has quality issues.',
                implode(' ', $issues),
                $details
            );
        }

        return CheckResult::pass(
            $this->getName(),
            sprintf('llms.txt found and looks well-structured (%d bytes).', strlen($body)),
            $details
        );
    }
}
