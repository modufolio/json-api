<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Date Filter for JSON:API
 *
 * Supports filtering by date ranges with before/after operators
 *
 * Example usage:
 * GET /api/contacts?filter[createdAt][after]=2024-01-01
 * GET /api/contacts?filter[createdAt][before]=2024-12-31
 * GET /api/contacts?filter[updatedAt][after]=2024-01-01&filter[updatedAt][before]=2024-12-31
 */
class DateFilter implements FilterInterface
{
    /**
     * @param array<string> $properties List of date fields to filter on
     */
    public function __construct(
        private array $properties = []
    ) {}

    public function apply(
        QueryBuilder $qb,
        array $params,
        array $fieldMappings,
        string $alias = 't0'
    ): array {
        $bindings = [];
        $paramCounter = 0;

        foreach ($this->properties as $property) {
            if (!isset($params[$property])) {
                continue;
            }

            $value = $params[$property];
            if (!is_array($value)) {
                continue;
            }

            $column = $fieldMappings[$property]['columnName'] ?? $property;

            // Handle 'after' operator (greater than or equal)
            if (isset($value['after'])) {
                $paramName = "date_{$property}_after_{$paramCounter}";
                $qb->andWhere("$alias.$column >= :$paramName");
                $bindings[$paramName] = $value['after'];
                $paramCounter++;
            }

            // Handle 'before' operator (less than or equal)
            if (isset($value['before'])) {
                $paramName = "date_{$property}_before_{$paramCounter}";
                $qb->andWhere("$alias.$column <= :$paramName");
                $bindings[$paramName] = $value['before'];
                $paramCounter++;
            }

            // Handle 'strictly_after' operator (greater than)
            if (isset($value['strictly_after'])) {
                $paramName = "date_{$property}_strictly_after_{$paramCounter}";
                $qb->andWhere("$alias.$column > :$paramName");
                $bindings[$paramName] = $value['strictly_after'];
                $paramCounter++;
            }

            // Handle 'strictly_before' operator (less than)
            if (isset($value['strictly_before'])) {
                $paramName = "date_{$property}_strictly_before_{$paramCounter}";
                $qb->andWhere("$alias.$column < :$paramName");
                $bindings[$paramName] = $value['strictly_before'];
                $paramCounter++;
            }
        }

        return $bindings;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'DateFilter',
            'description' => 'Filter resources by date ranges',
            'operators' => [
                'after' => '>= (greater than or equal)',
                'before' => '<= (less than or equal)',
                'strictly_after' => '> (strictly greater than)',
                'strictly_before' => '< (strictly less than)',
            ],
            'properties' => $this->properties,
            'example' => 'filter[createdAt][after]=2024-01-01&filter[createdAt][before]=2024-12-31',
        ];
    }

    public function supports(string $field): bool
    {
        return in_array($field, $this->properties, true);
    }
}
