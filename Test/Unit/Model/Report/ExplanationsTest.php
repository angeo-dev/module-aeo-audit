<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Report;

use Angeo\AeoAudit\Model\Report\Explanations;
use PHPUnit\Framework\TestCase;

class ExplanationsTest extends TestCase
{
    public function testEveryRegisteredCheckerIsExplained(): void
    {
        // Comments removed: the file's header comment holds an example
        // AuditRunner block with a made-up checker.
        $xml = (string) preg_replace('#<!--.*?-->#s', '', (string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        preg_match('#<type name="Angeo\\\\AeoAudit\\\\Model\\\\AuditRunner">(.*?)</type>#s', $xml, $block);
        preg_match_all('#<item name="([a-z_]+)"#', $block[1] ?? '', $items);

        self::assertGreaterThan(15, count($items[1]));
        $explanations = new Explanations();
        foreach ($items[1] as $code) {
            self::assertNotNull($explanations->get($code), 'no explanation for ' . $code);
        }
        self::assertNotNull($explanations->get('brand_visibility'));
    }

    public function testEntriesAreShortAndComplete(): void
    {
        foreach ((new Explanations())->all() as $code => $entry) {
            foreach (['what', 'why', 'how'] as $part) {
                self::assertTrue(trim($entry[$part]) !== '', $code . '.' . $part . ' is empty');
                self::assertLessThanOrEqual(260, mb_strlen($entry[$part]), $code . '.' . $part . ' is long');
            }
        }
    }
}
