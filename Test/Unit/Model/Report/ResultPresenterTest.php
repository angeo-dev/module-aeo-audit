<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Model\Report\ResultPresenter;
use PHPUnit\Framework\TestCase;

class ResultPresenterTest extends TestCase
{
    private ResultPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ResultPresenter();
    }

    /** @return array<int,array<string,mixed>> */
    private function stored(): array
    {
        return [
            ['code' => 'robots_txt', 'name' => 'robots.txt', 'status' => 'fail', 'weight' => 1.0, 'applicable' => true, 'details' => []],
            ['code' => 'llms_txt', 'name' => 'llms.txt', 'status' => 'warn', 'weight' => 0.5, 'applicable' => true, 'details' => []],
            ['code' => 'sitemap', 'name' => 'sitemap', 'status' => 'pass', 'weight' => 0.8, 'applicable' => true, 'details' => []],
            ['code' => 'agent_card', 'name' => 'A2A card', 'status' => 'pass', 'weight' => 0.6, 'applicable' => false, 'details' => ['applicable' => false]],
            ['code' => 'brand_visibility', 'name' => 'Brand visibility', 'status' => 'warn', 'weight' => 0.8, 'details' => ['score_group' => 'visibility', 'score' => 41, 'score_margin' => 6.5]],
        ];
    }

    public function testScoreMatchesAuditReportArithmetic(): void
    {
        // (0 + 0.25 + 0.8) / (1.0 + 0.5 + 0.8) = 45.65 → 46. Not applicable and
        // visibility are left out of both sides, as in AuditReport.
        self::assertSame(46, $this->presenter->score($this->presenter->normalize($this->stored())));
    }

    public function testGainsAddUpToWhatIsMissing(): void
    {
        $checks = $this->presenter->normalize($this->stored());
        $sum    = array_sum(array_map(static fn (array $c): float => $c['gain'], $checks));

        self::assertLessThanOrEqual(0.2, abs($sum - (100 - 45.65)));
        self::assertSame(43.5, $checks[0]['gain']);
        self::assertSame(10.9, $checks[1]['gain']);
        self::assertSame(0.0, $checks[3]['gain'], 'not applicable');
        self::assertSame(0.0, $checks[4]['gain'], 'visibility');
    }

    public function testFixFirstIsSortedByGainAndSkipsPasses(): void
    {
        $fix = $this->presenter->fixFirst($this->presenter->normalize($this->stored()));

        self::assertSame(['robots_txt', 'llms_txt'], array_column($fix, 'code'));
    }

    public function testOrderedPutsNotApplicableLast(): void
    {
        $codes = array_column($this->presenter->ordered($this->presenter->normalize($this->stored())), 'code');

        self::assertSame('robots_txt', $codes[0]);
        self::assertSame('agent_card', end($codes));
    }

    public function testLegacyKeysFromVersion3AreRead(): void
    {
        $checks = $this->presenter->normalize([
            ['check_code' => 'robots_txt', 'check_name' => 'robots.txt', 'status' => 'pass', 'weight' => 1],
            ['name' => 'Open Graph tags', 'status' => 'odd', 'weight' => 0.7],
        ]);

        self::assertSame('robots_txt', $checks[0]['code']);
        self::assertSame('open_graph_tags', $checks[1]['code']);
        self::assertSame('fail', $checks[1]['status']);
    }

    public function testChangesSincePreviousAudit(): void
    {
        $now    = $this->presenter->normalize($this->stored());
        $before = $this->presenter->normalize([
            ['code' => 'robots_txt', 'name' => 'robots.txt', 'status' => 'pass', 'weight' => 1.0],
            ['code' => 'llms_txt', 'name' => 'llms.txt', 'status' => 'fail', 'weight' => 0.5],
            ['code' => 'old_signal', 'name' => 'Old', 'status' => 'pass', 'weight' => 0.5],
        ]);
        $changes = $this->presenter->changes($now, $before);

        self::assertSame([['name' => 'robots.txt', 'from' => 'pass', 'to' => 'fail']], $changes['worse']);
        self::assertSame([['name' => 'llms.txt', 'from' => 'fail', 'to' => 'warn']], $changes['better']);
        self::assertSame(['Old'], $changes['removed']);
        self::assertCount(3, $changes['added']);
    }

    public function testHeatmapRowsAndColumns(): void
    {
        $map = $this->presenter->heatmap([
            ['id' => 1, 'created_at' => '2026-11-01 10:00:00', 'checks' => [['code' => 'robots_txt', 'name' => 'robots.txt', 'status' => 'fail', 'weight' => 1.0]]],
            ['id' => 2, 'created_at' => '2026-11-08 10:00:00', 'checks' => $this->stored()],
        ]);

        self::assertSame([0, 46], array_column($map['columns'], 'score'));
        self::assertSame('robots_txt', $map['rows'][0]['code']);
        self::assertSame(['fail', 'fail'], $map['rows'][0]['cells']);
        $byCode = array_column($map['rows'], 'cells', 'code');
        self::assertSame(['none', 'pass'], $byCode['sitemap']);
        self::assertSame(['none', 'na'], $byCode['agent_card']);
        self::assertArrayNotHasKey('brand_visibility', $byCode);
    }

    public function testEvidenceRows(): void
    {
        $rows = $this->presenter->evidence([
            'applicable'      => true,
            'url'             => 'https://shop.example/robots.txt',
            'blocked_search'  => ['OAI-SearchBot', 'PerplexityBot'],
            'matrix'          => ['ucp' => ['present' => true]],
            'sitemap_listed'  => false,
            'empty'           => [],
        ]);

        self::assertArrayNotHasKey('applicable', $rows);
        self::assertArrayNotHasKey('empty', $rows);
        self::assertSame('OAI-SearchBot, PerplexityBot', $rows['blocked search']);
        self::assertSame('{"ucp":{"present":true}}', $rows['matrix']);
        self::assertSame('no', $rows['sitemap listed']);
    }

    public function testRingGeometry(): void
    {
        $ring = $this->presenter->ring(50);

        self::assertSame(round($ring['circumference'] / 2, 2), $ring['dash']);
        self::assertSame('improve', $ring['band']);
        self::assertSame('excellent', $this->presenter->ring(120)['band']);
    }
}
