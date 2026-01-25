<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Registry for JSON:API filters
 *
 * Manages filters per resource class and applies them to queries
 */
class FilterRegistry
{
    /** @var array<string, FilterInterface[]> */
    private array $filters = [];

    /**
     * Register a filter for a resource class
     *
     * @param string $resourceClass Fully qualified class name
     * @param FilterInterface $filter The filter to register
     * @return void
     */
    public function register(string $resourceClass, FilterInterface $filter): void
    {
        if (!isset($this->filters[$resourceClass])) {
            $this->filters[$resourceClass] = [];
        }

        $this->filters[$resourceClass][] = $filter;
    }

    /**
     * Get all filters registered for a resource class
     *
     * @param string $resourceClass
     * @return FilterInterface[]
     */
    public function getFilters(string $resourceClass): array
    {
        return $this->filters[$resourceClass] ?? [];
    }

    /**
     * Check if any filters are registered for a resource class
     *
     * @param string $resourceClass
     * @return bool
     */
    public function hasFilters(string $resourceClass): bool
    {
        return !empty($this->filters[$resourceClass]);
    }

    /**
     * Apply all registered filters for a resource class to a query builder
     *
     * @param string $resourceClass The resource class
     * @param QueryBuilder $qb The DBAL query builder
     * @param array $params Filter parameters from the request
     * @param array $fieldMappings Entity field to column mappings
     * @param string $alias Table alias (default: 't0')
     * @return array Combined bindings from all filters
     */
    public function applyFilters(
        string $resourceClass,
        QueryBuilder $qb,
        array $params,
        array $fieldMappings,
        string $alias = 't0'
    ): array {
        $allBindings = [];
        $filters = $this->getFilters($resourceClass);

        foreach ($filters as $filter) {
            $bindings = $filter->apply($qb, $params, $fieldMappings, $alias);
            $allBindings = array_merge($allBindings, $bindings);
        }

        return $allBindings;
    }

    /**
     * Get description of all filters for a resource class
     *
     * @param string $resourceClass
     * @return array
     */
    public function getFilterDescriptions(string $resourceClass): array
    {
        $descriptions = [];
        $filters = $this->getFilters($resourceClass);

        foreach ($filters as $filter) {
            $descriptions[] = $filter->getDescription();
        }

        return $descriptions;
    }
}
