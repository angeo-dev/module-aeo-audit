<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\ResourceModel\AuditResult\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid Collection for Angeo AEO Audit Results UI listing.
 *
 * Extends SearchResult (not AbstractCollection) so the UI component
 * DataProvider receives arguments in the correct order:
 * entityFactory, logger, fetchStrategy, eventManager, mainTable, resourceModel, connection
 *
 * DO NOT extend AuditResultCollection here — that causes argument
 * mismatch when Magento's DI interceptor is generated.
 */
class Collection extends SearchResult implements SearchResultInterface
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface        $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface       $eventManager,
        string                 $mainTable = 'angeo_aeo_audit_result',
        ?string                $resourceModel = \Angeo\AeoAudit\Model\ResourceModel\AuditResult::class,
        ?AdapterInterface      $connection = null,
        ?AbstractDb            $resource = null
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $connection,
            $resource
        );
    }

    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    public function setAggregations($aggregations): static
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria): static
    {
        return $this;
    }

    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    public function setTotalCount($totalCount): static
    {
        return $this;
    }

    public function setItems(?array $items = null): static
    {
        return $this;
    }
}
