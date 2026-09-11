<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Storage for aggregated AI-bot hit counters.
 *
 * Deliberately NOT an AbstractDb entity: rows are pure counters written on
 * the hot request path (frontend plugin) and read once per audit. The write
 * is a single atomic INSERT ... ON DUPLICATE KEY UPDATE — no model
 * hydration, no events, no save() overhead.
 *
 * PRIVACY: this table stores (bot_code, bot_class, store_id, hit_date,
 * hits, last_seen) and nothing else. No IPs, no URLs, no raw user agents —
 * aggregation happens before the write, so no personal data is ever
 * persisted. Retention is enforced by pruneOlderThan() from the weekly cron.
 *
 * @api
 * @since 4.0.0
 */
class BotHit
{
    public const TABLE = 'angeo_aeo_bot_hit';

    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Record one hit for a bot, atomically. Safe under concurrency:
     * INSERT ... ON DUPLICATE KEY UPDATE increments without read-modify-write.
     *
     * Never throws — a broken counter must never break a storefront request.
     */
    public function recordHit(string $botCode, string $botClass, int $storeId): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName(self::TABLE);

            $connection->query(
                sprintf(
                    'INSERT INTO %s (bot_code, bot_class, store_id, hit_date, hits, last_seen)'
                    . ' VALUES (:bot_code, :bot_class, :store_id, CURRENT_DATE, 1, NOW())'
                    . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW()',
                    $table
                ),
                ['bot_code' => $botCode, 'bot_class' => $botClass, 'store_id' => $storeId]
            );
        } catch (\Throwable) {
            // Swallow — instrumentation must never affect the storefront.
            return;
        }
    }

    /**
     * Aggregated hits per bot for a store over the trailing window.
     *
     * @return array<string, array{hits: int, last_seen: string}>
     */
    public function getAggregates(int $storeId, int $days): array
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, [
                'bot_code',
                'hits'      => 'SUM(hits)',
                'last_seen' => 'MAX(hit_date)',
            ])
            ->where('store_id = ?', $storeId)
            ->where('hit_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)', $days)
            ->group('bot_code');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(string) $row['bot_code']] = [
                'hits'      => (int) $row['hits'],
                'last_seen' => (string) $row['last_seen'],
            ];
        }

        return $result;
    }

    /**
     * True when at least one row exists for the store — distinguishes
     * "instrumentation never saw a bot" from "table freshly created".
     */
    public function hasAnyData(int $storeId): bool
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['cnt' => 'COUNT(*)'])
            ->where('store_id = ?', $storeId)
            ->limit(1);

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * GDPR-friendly retention: drop counters older than N days.
     * Called from the weekly cron.
     */
    public function pruneOlderThan(int $days): int
    {
        $connection = $this->resource->getConnection();
        $table      = $this->resource->getTableName(self::TABLE);

        return $connection->delete(
            $table,
            ['hit_date < DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)' => $days]
        );
    }
}
