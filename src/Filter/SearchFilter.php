<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Search Filter for JSON:API
 *
 * Supports partial and exact matching strategies for text fields
 *
 * Example usage:
 * new SearchFilter([
 *     'name' => SearchStrategy::PARTIAL,    // LIKE %value%
 *     'email' => SearchStrategy::EXACT,     // = value
 *     'city' => SearchStrategy::START,      // LIKE value%
 *     'country' => SearchStrategy::END      // LIKE %value
 * ])
 */
class SearchFilter implements FilterInterface
{
    /**
     * @param array<string, SearchStrategy|string> $properties Field => strategy mapping
     */
    public function __construct(
        private array $properties = []
    ) {
        // Normalize string values to enum for backwards compatibility
        foreach ($this->properties as $field => $strategy) {
            if (is_string($strategy)) {
                $this->properties[$field] = SearchStrategy::from($strategy);
            }
        }
    }

    public function apply(
        QueryBuilder $qb,
        array $params,
        array $fieldMappings,
        string $alias = 't0'
    ): array {
        $bindings = [];
        $paramCounter = 0;

        foreach ($this->properties as $property => $strategy) {
            if (!isset($params[$property])) {
                continue;
            }

            $value = $params[$property];
            $column = $fieldMappings[$property]['columnName'] ?? $property;
            $paramName = "search_{$property}_{$paramCounter}";

            match ($strategy) {
                SearchStrategy::PARTIAL => [
                    $qb->andWhere("$alias.$column LIKE :$paramName"),
                    $bindings[$paramName] = "%{$value}%"
                ],
                SearchStrategy::START => [
                    $qb->andWhere("$alias.$column LIKE :$paramName"),
                    $bindings[$paramName] = "{$value}%"
                ],
                SearchStrategy::END => [
                    $qb->andWhere("$alias.$column LIKE :$paramName"),
                    $bindings[$paramName] = "%{$value}"
                ],
                SearchStrategy::EXACT => [
                    $qb->andWhere("$alias.$column = :$paramName"),
                    $bindings[$paramName] = $value
                ],
            };

            $paramCounter++;
        }

        return $bindings;
    }

    public function getDescription(): array
    {
        return [
            'type' => 'SearchFilter',
            'description' => 'Text search filter with multiple strategies',
            'strategies' => [
                SearchStrategy::PARTIAL->value => 'LIKE %value% (contains)',
                SearchStrategy::EXACT->value => '= value (exact match)',
                SearchStrategy::START->value => 'LIKE value% (starts with)',
                SearchStrategy::END->value => 'LIKE %value (ends with)',
            ],
            'properties' => array_map(
                fn(SearchStrategy $s) => $s->value,
                $this->properties
            ),
        ];
    }

    public function supports(string $field): bool
    {
        return isset($this->properties[$field]);
    }
}
