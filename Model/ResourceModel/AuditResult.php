<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AuditResult extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('angeo_aeo_audit_result', 'id');
    }

    /**
     * Keep only the latest $limit records per store — prune old history.
     *
     * MariaDB does not support LIMIT inside a subquery used with IN/NOT IN
     * (SQLSTATE 42000 / error 1235). Workaround: fetch the IDs to keep first
     * as a plain PHP array, then DELETE in a separate query using those IDs.
     */
    public function pruneOldResults(string $storeCode, int $keep = 50): void
    {
        $connection = $this->getConnection();
        $table      = $this->getMainTable();

        // Step 1 — fetch the IDs we want to KEEP (PHP array, no subquery)
        $keepIds = $connection->fetchCol(
            $connection->select()
                ->from($table, 'id')
                ->where('store_code = ?', $storeCode)
                ->order('created_at DESC')
                ->limit($keep)
        );

        // Nothing to prune yet
        if (empty($keepIds)) {
            return;
        }

        // Step 2 — delete everything else for this store
        $connection->delete(
            $table,
            [
                'store_code = ?' => $storeCode,
                'id NOT IN (?)'  => $keepIds,
            ]
        );
    }
}
