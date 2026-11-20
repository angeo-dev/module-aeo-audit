<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

/**
 * Turns a stored audit (checks_json) into what the result page draws.
 *
 * Pure PHP, unit tested. It reads rows stored by any 4.x version, and by
 * 3.x (which named the keys check_code / check_name).
 *
 * The numbers follow AuditReport::getScorePercent(): readiness signals only,
 * not-applicable results left out, pass = full weight, warn = half, fail = 0.
 * "Gain" is the points a signal would add to the readiness score if it
 * passed: weight × (1 − earned share) ÷ total weight × 100. The gains of all
 * signals add up to 100 minus the score, so the "fix first" chart and the
 * ring always agree.
 *
 * @api
 * @since 4.5.0
 */
class ResultPresenter
{
    private const STATUS_SHARE = ['pass' => 1.0, 'warn' => 0.5, 'fail' => 0.0];

    /**
     * @param array<int,array<string,mixed>> $rawChecks checks_json, decoded
     * @return array<int,array{code:string,name:string,status:string,weight:float,applicable:bool,visibility:bool,
     *     gain:float,message:string,recommendation:string,fix_command:string,details:array<string,mixed>,
     *     category:string,severity:string}>
     */
    public function normalize(array $rawChecks): array
    {
        $checks = [];
        foreach ($rawChecks as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $details = is_array($raw['details'] ?? null) ? $raw['details'] : [];
            $status  = (string) ($raw['status'] ?? 'fail');
            $name    = (string) ($raw['name'] ?? $raw['check_name'] ?? '');
            $code    = (string) ($raw['code'] ?? $raw['check_code'] ?? '');
            if ($code === '') {
                $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
            }

            $checks[] = [
                'code'           => $code,
                'name'           => $name !== '' ? $name : $code,
                'status'         => isset(self::STATUS_SHARE[$status]) ? $status : 'fail',
                'weight'         => max(0.0, (float) ($raw['weight'] ?? 1.0)),
                'applicable'     => ($raw['applicable'] ?? true) !== false && ($details['applicable'] ?? true) !== false,
                'visibility'     => ($details['score_group'] ?? '') === 'visibility',
                'gain'           => 0.0,
                'message'        => (string) ($raw['message'] ?? ''),
                'recommendation' => (string) ($raw['recommendation'] ?? ''),
                'fix_command'    => (string) ($raw['fix_command'] ?? ''),
                'details'        => $details,
                'category'       => (string) ($raw['category'] ?? ''),
                'severity'       => (string) ($raw['severity'] ?? ''),
            ];
        }

        $total = $this->weightTotal($checks);
        foreach ($checks as $i => $check) {
            if ($total > 0.0 && $this->isScored($check)) {
                $lost = $check['weight'] * (1.0 - self::STATUS_SHARE[$check['status']]);
                $checks[$i]['gain'] = round(100 * $lost / $total, 1);
            }
        }

        return $checks;
    }

    /**
     * Readiness signals that count: applicable and not measured visibility.
     *
     * @param array<int,array<string,mixed>> $checks normalized
     * @return array<int,array<string,mixed>>
     */
    public function scored(array $checks): array
    {
        return array_values(array_filter($checks, fn (array $c): bool => $this->isScored($c)));
    }

    /**
     * @param array<int,array<string,mixed>> $checks normalized
     * @return array<int,array<string,mixed>>
     */
    public function visibility(array $checks): array
    {
        return array_values(array_filter($checks, static fn (array $c): bool => $c['visibility'] && $c['applicable']));
    }

    /**
     * @param array<int,array<string,mixed>> $checks normalized
     * @return array<int,array<string,mixed>>
     */
    public function notApplicable(array $checks): array
    {
        return array_values(array_filter($checks, static fn (array $c): bool => !$c['applicable']));
    }

    /**
     * Readiness score from the checks. Same arithmetic as AuditReport; used
     * where only checks_json is at hand (trend heatmap, assistant facts).
     *
     * @param array<int,array<string,mixed>> $checks normalized
     */
    public function score(array $checks): int
    {
        $total = $this->weightTotal($checks);
        if ($total <= 0.0) {
            return 0;
        }
        $earned = 0.0;
        foreach ($this->scored($checks) as $c) {
            $earned += $c['weight'] * self::STATUS_SHARE[$c['status']];
        }

        return (int) round(100 * $earned / $total);
    }

    /**
     * Failed and warning signals, the largest gain first.
     *
     * @param array<int,array<string,mixed>> $checks normalized
     * @return array<int,array<string,mixed>>
     */
    public function fixFirst(array $checks, int $limit = 8): array
    {
        $rows = array_values(array_filter($this->scored($checks), static fn (array $c): bool => $c['gain'] > 0));
        usort($rows, static fn (array $a, array $b): int => [$b['gain'], $b['weight']] <=> [$a['gain'], $a['weight']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Every check in reading order: fail, warn, pass, not applicable; then by
     * gain, then by weight.
     *
     * @param array<int,array<string,mixed>> $checks normalized
     * @return array<int,array<string,mixed>>
     */
    public function ordered(array $checks): array
    {
        $rank = static fn (array $c): int => !$c['applicable'] ? 3 : ['fail' => 0, 'warn' => 1, 'pass' => 2][$c['status']];
        usort($checks, static fn (array $a, array $b): int =>
            [$rank($a), -$a['gain'], -$a['weight']] <=> [$rank($b), -$b['gain'], -$b['weight']]);

        return $checks;
    }

    /**
     * What changed since an earlier audit of the same store.
     *
     * @param array<int,array<string,mixed>> $current  normalized
     * @param array<int,array<string,mixed>> $previous normalized
     * @return array{better:array<int,array<string,string>>,worse:array<int,array<string,string>>,
     *     added:array<int,string>,removed:array<int,string>}
     */
    public function changes(array $current, array $previous): array
    {
        $order = ['fail' => 0, 'warn' => 1, 'pass' => 2];
        $before = [];
        foreach ($previous as $c) {
            $before[$c['code']] = $c;
        }

        $out = ['better' => [], 'worse' => [], 'added' => [], 'removed' => []];
        foreach ($current as $c) {
            $old = $before[$c['code']] ?? null;
            unset($before[$c['code']]);
            if ($old === null) {
                $out['added'][] = $c['name'];
                continue;
            }
            if (!$c['applicable'] || !$old['applicable'] || $old['status'] === $c['status']) {
                continue;
            }
            $row = ['name' => $c['name'], 'from' => $old['status'], 'to' => $c['status']];
            $out[$order[$c['status']] > $order[$old['status']] ? 'better' : 'worse'][] = $row;
        }
        foreach ($before as $old) {
            $out['removed'][] = $old['name'];
        }

        return $out;
    }

    /**
     * Status of each signal across several audits, for the trend heatmap.
     * Columns are audits (oldest first), rows are signal codes.
     *
     * @param array<int,array{id:int,created_at:string,checks:array<int,array<string,mixed>>}> $audits oldest first, raw checks
     * @return array{columns:array<int,array{id:int,created_at:string,score:int}>,
     *     rows:array<int,array{code:string,name:string,weight:float,cells:array<int,string>}>}
     */
    public function heatmap(array $audits): array
    {
        $columns = [];
        $rows    = [];
        foreach ($audits as $col => $audit) {
            $checks = $this->normalize($audit['checks']);
            $columns[] = ['id' => (int) $audit['id'], 'created_at' => (string) $audit['created_at'], 'score' => $this->score($checks)];
            foreach ($checks as $c) {
                if ($c['visibility']) {
                    continue;
                }
                $rows[$c['code']] ??= ['code' => $c['code'], 'name' => $c['name'], 'weight' => $c['weight'], 'cells' => []];
                $rows[$c['code']]['cells'][$col] = $c['applicable'] ? $c['status'] : 'na';
                $rows[$c['code']]['weight'] = $c['weight'];
            }
        }

        foreach ($rows as $code => $row) {
            $cells = [];
            foreach (array_keys($columns) as $col) {
                $cells[$col] = $row['cells'][$col] ?? 'none';
            }
            $rows[$code]['cells'] = $cells;
        }
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['weight'], $a['name']] <=> [$a['weight'], $b['name']]);

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * @param array<int,array<string,mixed>> $checks normalized
     */
    public function weightTotal(array $checks): float
    {
        $total = 0.0;
        foreach ($checks as $c) {
            if ($this->isScored($c)) {
                $total += $c['weight'];
            }
        }

        return $total;
    }

    /**
     * Score ring geometry: the arc length for a 0–100 score on a circle of
     * radius $r. Kept here so the template has no arithmetic.
     *
     * @return array{circumference:float,dash:float,band:string}
     */
    public function ring(int $score, int $r = 50): array
    {
        $score = max(0, min(100, $score));
        $c     = 2 * M_PI * $r;

        return [
            'circumference' => round($c, 2),
            'dash'          => round($c * $score / 100, 2),
            'band'          => match (true) {
                $score >= 85 => 'excellent',
                $score >= 65 => 'good',
                $score >= 40 => 'improve',
                default      => 'critical',
            },
        ];
    }

    /**
     * A check's details as short "key → value" lines for the Evidence block.
     * Lists of plain values read as text; nested data as compact JSON.
     *
     * @param array<string,mixed> $details
     * @return array<string,string>
     */
    public function evidence(array $details, int $maxRows = 14, int $maxLength = 300): array
    {
        $rows = [];
        foreach ($details as $key => $value) {
            if (in_array($key, ['applicable', 'score_group'], true) || $value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_bool($value)) {
                $text = $value ? 'yes' : 'no';
            } elseif (is_scalar($value)) {
                $text = (string) $value;
            } elseif (is_array($value) && array_is_list($value) && array_filter($value, 'is_array') === []) {
                $text = implode(', ', array_map('strval', $value));
            } else {
                $text = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
            if (mb_strlen($text) > $maxLength) {
                $text = mb_substr($text, 0, $maxLength) . '…';
            }
            $rows[str_replace('_', ' ', (string) $key)] = $text;
            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $check normalized */
    private function isScored(array $check): bool
    {
        return $check['applicable'] && !$check['visibility'];
    }
}
