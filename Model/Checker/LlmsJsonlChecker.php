<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;

/**
 * Validates /llms.jsonl — machine-readable product catalog for AI pipelines.
 *
 * Checks:
 *  1.  HTTP 200 and non-empty body
 *  2.  Valid JSON Lines format (each line is valid JSON)
 *  3.  Required fields per line: name, url
 *  4.  eCommerce fields: price or sku present
 *  5.  No empty lines in the middle (breaks parsers)
 *  6.  Reasonable record count (warn if < 5)
 *  7.  File freshness via Last-Modified (warn if > 7 days)
 *  8.  Max file size (warn if > 10MB — recommend gzip)
 */
class LlmsJsonlChecker extends AbstractChecker
{
    /**
     * Required fields — checked case-insensitively, with aliases.
     *
     * module-llms-txt v1.1.x generates:
     *   "title" (not "name"), "url", "sku", "price", "type", "store"
     *
     * We accept "name" OR "title" for the product name field.
     */
    private const REQUIRED_FIELDS = ['url'];

    /**
     * Name field aliases — at least one must be present.
     * module-llms-txt uses "title"; other integrations may use "name".
     */
    private const NAME_FIELDS = ['title', 'name', 'product_name'];

    /**
     * eCommerce fields — at least one must be present (case-insensitive).
     * module-llms-txt generates: "price", "sku", "currency"
     */
    private const ECOM_FIELDS = ['price', 'sku', 'currency', 'brand', 'regular_price'];
    private const MIN_RECORDS        = 5;
    private const MAX_BYTES          = 10_485_760; // 10 MB
    private const STALE_DAYS         = 7;
    private const MAX_LINES_TO_CHECK = 20;

    public function getName(): string  { return 'llms.jsonl — machine-readable catalog'; }
    public function getCode(): string  { return 'llms_jsonl'; }
    public function getWeight(): float { return 0.75; }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    public function check(string $baseUrl): CheckResult
    {
        $base = $this->normalizeBase($baseUrl);
        $url  = $base . '/llms.jsonl';

        [$status, $body, $headers] = $this->fetchWithHeaders($url);

        // 1. Existence
        if ($status !== 200 || empty($body)) {
            return $this->fail(
                'llms.jsonl not found (HTTP ' . ($status ?: 'error') . ').',
                'Install angeo/module-llms-txt and run: bin/magento angeo:llms:generate — generates both llms.txt and llms.jsonl',
                ['url' => $url]
            );
        }

        $issues   = [];
        $warnings = [];
        $size     = strlen($body);

        // Split into lines, remove trailing empty
        $rawLines = explode("\n", trim($body));
        $lines    = array_filter($rawLines, fn(string $l) => trim($l) !== '');
        $total    = count($lines);

        // 2. JSON Lines format — check first N lines
        $invalidLines  = [];
        $missingFields = [];
        $missingEcom   = 0;
        $checked       = 0;

        foreach ($lines as $lineNum => $line) {
            if ($checked >= self::MAX_LINES_TO_CHECK) break;
            $checked++;

            $decoded = json_decode($line, true);

            if ($decoded === null) {
                $invalidLines[] = 'line ' . ($lineNum + 1) . ': ' . json_last_error_msg();
                continue;
            }

            // Normalize keys to lowercase for case-insensitive matching
            // module-llms-txt v1.1.x generates: title, url, sku, price, currency, type, store
            $normalizedKeys = array_change_key_case($decoded, CASE_LOWER);

            // 3. Required fields (url)
            foreach (self::REQUIRED_FIELDS as $field) {
                if (empty($normalizedKeys[$field])) {
                    $missingFields[$field] = ($missingFields[$field] ?? 0) + 1;
                }
            }

            // 3b. Name field — accept "title" OR "name" OR "product_name"
            $hasName = false;
            foreach (self::NAME_FIELDS as $nameField) {
                if (!empty($normalizedKeys[$nameField])) { $hasName = true; break; }
            }
            if (!$hasName) {
                $missingFields['name/title'] = ($missingFields['name/title'] ?? 0) + 1;
            }

            // 4. eCommerce fields — case-insensitive
            $hasEcom = false;
            foreach (self::ECOM_FIELDS as $field) {
                if (!empty($normalizedKeys[$field])) { $hasEcom = true; break; }
            }
            if (!$hasEcom) $missingEcom++;
        }

        // 5. Empty lines in middle
        $emptyInMiddle = 0;
        foreach ($rawLines as $i => $line) {
            if ($i > 0 && $i < count($rawLines) - 1 && trim($line) === '') {
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
            // Only warn if majority of records are missing ALL eCommerce fields
            // Partial absence (e.g. no gtin) is normal and expected
            if ($pct > 80) {
                $warnings[] = sprintf(
                    '%d%% of records missing all eCommerce fields (price/sku/currency) — check feed generation',
                    $pct
                );
            }
        }

        // 6. Record count
        if ($total < self::MIN_RECORDS) {
            $warnings[] = sprintf(
                'Only %d record(s) — expected more for a product catalog (check generation settings)',
                $total
            );
        }

        // 7. Freshness
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

        // 8. File size
        if ($size > self::MAX_BYTES) {
            $warnings[] = sprintf(
                'File is %.1fMB — consider serving as llms.jsonl.gz for faster AI crawler access',
                $size / 1_048_576
            );
        }

        $details = [
            'url'           => $url,
            'size_bytes'    => $size,
            'total_records' => $total,
            'checked'       => $checked,
            'invalid_lines' => $invalidLines,
            'missing_fields'=> $missingFields,
            'last_modified' => $lastModified,
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

    /**
     * @return array{int, string, array<string, string>}
     */
    private function fetchWithHeaders(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 10,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'Angeo-AEO-Audit/1.0',
                'ignore_errors'   => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $body   = @file_get_contents($url, false, $ctx);
        $status = 0;
        $hdrs   = [];

        if (isset($http_response_header)) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m)) {
                $status = (int) $m[1];
            }
            foreach ($http_response_header as $h) {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $hdrs[strtolower(trim($k))] = trim($v);
                }
            }
        }

        return [$status, (string) $body, $hdrs];
    }
}
