<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;

/**
 * Default JSON:API Filter Handler
 *
 * Handles all standard filter operations including operators like gt, lt, like, in, etc.
 * This is the default filter applied to all resources unless overridden.
 */
class JsonApiFilterHandler implements FilterInterface
{
    private array $operators = [
        'eq' => '=',
        'neq' => '!=',
        'not' => '!=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'like' => 'LIKE',
        'in' => 'IN',
        'null' => 'IS NULL',
        'not_null' => 'IS NOT NULL',
    ];

    /** @var array List of allowed fields for this filter */
    private array $allowedFields;

    /**
     * Constructor
     *
     * @param array $allowedFields List of fields this filter can operate on (empty = all fields)
     */
    public function __construct(array $allowedFields = [])
    {
        $this->allowedFields = $allowedFields;
    }

    /**
     * Apply filters to a query builder
     *
     * @param QueryBuilder $qb
     * @param array $filters
     * @param array $fieldMappings
     * @param string $alias
     * @return array Bindings for the query
     */
    public function apply(
        QueryBuilder $qb,
        array $filters,
        array $fieldMappings,
        string $alias = 't0'
    ): array {
        $bindings = [];
        $i = 0;

        foreach ($filters as $field => $value) {
            // Ensure field is a string - skip if numeric key (malformed filter)
            if (!is_string($field)) {
                continue;
            }

            // Skip if field is not supported by this filter
            if (!empty($this->allowedFields) && !$this->supports($field)) {
                continue;
            }

            // Get the actual column name from field mappings
            $column = $fieldMappings[$field]['columnName'] ?? $field;

            // Validate column name is a string
            if (!is_string($column)) {
                throw new InvalidArgumentException(
                    "Invalid column mapping for field '{$field}'. Expected string, got " . gettype($column)
                );
            }

            if (is_array($value)) {
                $this->applyComplexFilter($qb, $alias, $column, $value, $i, $bindings);
            } else {
                $this->applySimpleFilter($qb, $alias, $column, $value, $i, $bindings);
            }

            $i++;
        }

        return $bindings;
    }

    /**
     * Apply complex filter with operators
     *
     * @param QueryBuilder $qb
     * @param string $alias
     * @param string $column
     * @param array $value
     * @param int $i
     * @param array $bindings
     * @return void
     */
    private function applyComplexFilter(
        QueryBuilder $qb,
        string $alias,
        string $column,
        array $value,
        int &$i,
        array &$bindings
    ): void {
        if (array_key_exists('null', $value)) {
            $qb->andWhere("$alias.$column IS NULL");
        } elseif (isset($value['not']) || isset($value['neq'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column != $param");
            $bindings["p{$i}"] = $value['not'] ?? $value['neq'];
        } elseif (isset($value['gt'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column > $param");
            $bindings["p{$i}"] = $value['gt'];
        } elseif (isset($value['gte'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column >= $param");
            $bindings["p{$i}"] = $value['gte'];
        } elseif (isset($value['lt'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column < $param");
            $bindings["p{$i}"] = $value['lt'];
        } elseif (isset($value['lte'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column <= $param");
            $bindings["p{$i}"] = $value['lte'];
        } elseif (isset($value['like'])) {
            $param = ":p{$i}";
            $qb->andWhere("$alias.$column LIKE $param");
            $bindings["p{$i}"] = $value['like'];
        } elseif (isset($value['in'])) {
            if (empty($value['in'])) {
                // Handle empty IN clause - always false condition
                $qb->andWhere('1 = 0');
                return;
            }

            $placeholders = [];
            foreach ($value['in'] as $j => $val) {
                $placeholders[] = ":p{$i}_{$j}";
                $bindings["p{$i}_{$j}"] = $val;
            }
            $qb->andWhere("$alias.$column IN (" . implode(',', $placeholders) . ')');
        } elseif (isset($value['not_null'])) {
            $qb->andWhere("$alias.$column IS NOT NULL");
        } else {
            throw new InvalidArgumentException("Unsupported filter operator on $column");
        }
    }

    /**
     * Apply simple filter (equality)
     *
     * @param QueryBuilder $qb
     * @param string $alias
     * @param string $column
     * @param mixed $value
     * @param int $i
     * @param array $bindings
     * @return void
     */
    private function applySimpleFilter(
        QueryBuilder $qb,
        string $alias,
        string $column,
        mixed $value,
        int $i,
        array &$bindings
    ): void {
        $param = ":p{$i}";
        $qb->andWhere("$alias.$column = $param");
        $bindings["p{$i}"] = $value;
    }

    /**
     * Get list of supported operators
     *
     * @return array
     */
    public function getSupportedOperators(): array
    {
        return array_keys($this->operators);
    }

    /**
     * Check if an operator is supported
     *
     * @param string $operator
     * @return bool
     */
    public function supportsOperator(string $operator): bool
    {
        return isset($this->operators[$operator]);
    }

    /**
     * Get description of the filter for documentation
     *
     * @return array
     */
    public function getDescription(): array
    {
        return [
            'type' => 'JsonApiFilterHandler',
            'description' => 'Handles standard JSON:API filter operators',
            'operators' => array_keys($this->operators),
            'fields' => $this->allowedFields ?: ['all'],
        ];
    }

    /**
     * Check if this filter supports the given field
     *
     * @param string $field
     * @return bool
     */
    public function supports(string $field): bool
    {
        // If no specific fields configured, support all fields
        if (empty($this->allowedFields)) {
            return true;
        }

        return in_array($field, $this->allowedFields, true);
    }
}