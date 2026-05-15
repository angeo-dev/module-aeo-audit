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
    /**
     * @param EntityFactoryInterface $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param string $mainTable
     * @param string|null $resourceModel
     * @param AdapterInterface|null $connection
     * @param AbstractDb|null $resource
     */
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

    /**
     * @return AggregationInterface
     */
    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    /**
     * @param mixed $aggregations
     * @return static
     */
    public function setAggregations($aggregations): static
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * @return SearchCriteriaInterface|null
     */
    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return static
     */
    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria): static
    {
        return $this;
    }

    /**
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    /**
     * @param mixed $totalCount
     * @return static
     */
    public function setTotalCount($totalCount): static
    {
        return $this;
    }

    /**
     * @param array|null $items
     * @return static
     */
    public function setItems(?array $items = null): static
    {
        return $this;
    }
}
