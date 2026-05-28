<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates /llms.jsonl — machine-readable product catalog for AI pipelines.
 */
class LlmsJsonlChecker extends AbstractChecker
{
    private const REQUIRED_FIELDS = ['url'];

    /** Name field aliases — at least one must be present. */
    private const NAME_FIELDS = ['title', 'name', 'product_name'];

    /** eCommerce fields — at least one must be present. */
    private const ECOM_FIELDS = ['price', 'sku', 'currency', 'brand', 'regular_price'];

    private const MIN_RECORDS        = 5;
    private const MAX_BYTES          = 10_485_760; // 10 MB
    private const STALE_DAYS         = 7;
    private const MAX_LINES_TO_CHECK = 20;

    public function getName(): string
    {
        return 'llms.jsonl — machine-readable catalog';
    }

    public function getCode(): string
    {
        return 'llms_jsonl';
    }

    public function getWeight(): float
    {
        return 0.75;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->urlSampler->getBaseUrl($store);
        $url  = $base . '/llms.jsonl';

        [$status, $body, $headers] = $this->fetchWithHeaders($url);

        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'llms.jsonl not found (HTTP ' . ($status ?: 'error') . ').',
                'Install angeo/module-llms-txt and run: bin/magento angeo:llms:generate'
                    . ' — generates both llms.txt and llms.jsonl',
                ['url' => $url]
            );
        }

        $issues   = [];
        $warnings = [];
        $size     = strlen($body);

        $rawLines = explode("\n", trim($body));
        $lines    = array_values(array_filter($rawLines, static fn(string $l) => trim($l) !== ''));
        $total    = count($lines);

        $invalidLines  = [];
        $missingFields = [];
        $missingEcom   = 0;
        $checked       = 0;

        foreach ($lines as $lineNum => $line) {
            if ($checked >= self::MAX_LINES_TO_CHECK) {
                break;
            }
            $checked++;

            $decoded = json_decode($line, true);
            if ($decoded === null) {
                $invalidLines[] = 'line ' . ($lineNum + 1) . ': ' . json_last_error_msg();
                continue;
            }
            if (!is_array($decoded)) {
                $invalidLines[] = 'line ' . ($lineNum + 1) . ': not a JSON object';
                continue;
            }

            $normalizedKeys = array_change_key_case($decoded, CASE_LOWER);

            foreach (self::REQUIRED_FIELDS as $field) {
                if (empty($normalizedKeys[$field])) {
                    $missingFields[$field] = ($missingFields[$field] ?? 0) + 1;
                }
            }

            $hasName = false;
            foreach (self::NAME_FIELDS as $nameField) {
                if (!empty($normalizedKeys[$nameField])) {
                    $hasName = true;
                    break;
                }
            }
            if (!$hasName) {
                $missingFields['name/title'] = ($missingFields['name/title'] ?? 0) + 1;
            }

            $hasEcom = false;
            foreach (self::ECOM_FIELDS as $field) {
                if (!empty($normalizedKeys[$field])) {
                    $hasEcom = true;
                    break;
                }
            }
            if (!$hasEcom) {
                $missingEcom++;
            }
        }

        $emptyInMiddle = 0;
        $rawLineCount  = count($rawLines);
        foreach ($rawLines as $i => $line) {
            if ($i > 0 && $i < $rawLineCount - 1 && trim($line) === '') {
                $emptyInMiddle++;
            }
        }
        if ($emptyInMiddle > 0) {
            $warnings[] = sprintf('%d empty line(s) in the middle — may break streaming JSON parsers', $emptyInMiddle);
        }

        if (!empty($invalidLines)) {
            $issues[] = sprintf(
                '%d invalid JSON line(s): %s',
                count($invalidLines),
                implode('; ', array_slice($invalidLines, 0, 3))
            );
        }

        foreach ($missingFields as $field => $count) {
            $issues[] = sprintf('"%s" missing in %d/%d checked records', $field, $count, $checked);
        }

        if ($missingEcom > 0 && $checked > 0) {
            $pct = (int) round($missingEcom / $checked * 100);
            if ($pct > 80) {
                $warnings[] = sprintf(
                    '%d%% of records missing all eCommerce fields (price/sku/currency) — check feed generation',
                    $pct
                );
            }
        }

        if ($total < self::MIN_RECORDS) {
            $warnings[] = sprintf(
                'Only %d record(s) — expected more for a product catalog (check generation settings)',
                $total
            );
        }

        $lastModified = $headers['last-modified'] ?? null;
        if ($lastModified) {
            $modTime = strtotime($lastModified);
            if ($modTime && (time() - $modTime) > (self::STALE_DAYS * 86400)) {
                $days = (int) round((time() - $modTime) / 86400);
                $warnings[] = sprintf(
                    'llms.jsonl is %d days old — enable cron or run: bin/magento angeo:llms:generate',
                    $days
                );
            }
        }

        if ($size > self::MAX_BYTES) {
            $warnings[] = sprintf(
                'File is %.1fMB — consider serving as llms.jsonl.gz for faster AI crawler access',
                $size / 1_048_576
            );
        }

        $details = [
            'url'            => $url,
            'size_bytes'     => $size,
            'total_records'  => $total,
            'checked'        => $checked,
            'invalid_lines'  => $invalidLines,
            'missing_fields' => $missingFields,
            'last_modified'  => $lastModified,
        ];

        if (!empty($issues)) {
            return $this->fail(
                sprintf('llms.jsonl has %d critical issue(s): %s', count($issues), $issues[0]),
                implode(' | ', array_merge($issues, $warnings)),
                $details
            );
        }

        if (!empty($warnings)) {
            return $this->warn(
                sprintf('llms.jsonl valid (%d records) — %d improvement(s)', $total, count($warnings)),
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            sprintf('llms.jsonl valid — %d records, all required fields present.', $total),
            $details
        );
    }
}
