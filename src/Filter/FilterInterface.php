<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Interface for JSON:API filters
 *
 * Filters are applied to collection queries to filter, search, or modify results
 */
interface FilterInterface
{
    /**
     * Apply the filter to a query builder
     *
     * @param QueryBuilder $qb The DBAL query builder
     * @param array<string, mixed> $params The filter parameters from the request
     * @param array<string, mixed> $fieldMappings Entity field to column mappings
     * @param string $alias Table alias (default: 't0')
     * @return array<string, mixed> Bindings for the query parameters
     */
    public function apply(
        QueryBuilder $qb,
        array $params,
        array $fieldMappings,
        string $alias = 't0'
    ): array;

    /**
     * Get description of the filter for documentation
     *
     * @return array<string, mixed> Filter description
     */
    public function getDescription(): array;

    /**
     * Check if this filter supports the given field
     *
     * @param string $field The field name
     * @return bool
     */
    public function supports(string $field): bool;
}
