<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;
use Modufolio\JsonApi\Exception\QueryParamMalformed;

/**
 * Range Filter for JSON:API
 *
 * Bounds a numeric (or otherwise orderable) field. The catch-all
 * {@see JsonApiFilterHandler} already offers `gt`/`gte`/`lt`/`lte`; this filter
 * exists to restrict those operators to an explicit set of fields and to add
 * `between`, which cannot be expressed as a single comparison.
 *
 * Example usage:
 * GET /api/products?filter[price][gte]=10&filter[price][lt]=100
 * GET /api/products?filter[price][between]=10..100
 */
class RangeFilter implements FilterInterface
{
    private const OPERATORS = [
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    /**
     * @param array<string> $properties List of fields to bound
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
            if (!isset($params[$property]) || !is_array($params[$property])) {
                continue;
            }

            $column = $fieldMappings[$property]['columnName'] ?? $property;

            foreach ($params[$property] as $operator => $value) {
                if (isset(self::OPERATORS[$operator])) {
                    $paramName = "range_{$property}_{$operator}_{$paramCounter}";
                    $qb->andWhere("$alias.$column " . self::OPERATORS[$operator] . " :$paramName");
                    $bindings[$paramName] = $value;
                    $paramCounter++;
                    continue;
                }

                if ($operator === 'between') {
                    [$from, $to] = $this->parseBetween($property, $value);

                    $fromParam = "range_{$property}_from_{$paramCounter}";
                    $toParam = "range_{$property}_to_{$paramCounter}";
                    // Two comparisons rather than SQL BETWEEN: the inclusive
                    // semantics are identical everywhere, while BETWEEN's
                    // handling of a reversed pair is not.
                    $qb->andWhere("$alias.$column >= :$fromParam");
                    $qb->andWhere("$alias.$column <= :$toParam");
                    $bindings[$fromParam] = $from;
                    $bindings[$toParam] = $to;
                    $paramCounter++;
                }
            }
        }

        return $bindings;
    }

    /**
     * `between` carries two bounds in one value, written `from..to`.
     *
     * @return array{0: string, 1: string}
     */
    private function parseBetween(string $property, mixed $value): array
    {
        if (!is_string($value) || !str_contains($value, '..')) {
            throw new QueryParamMalformed(
                "filter[$property][between]",
                "The 'between' operator takes two bounds written 'from..to'.",
            );
        }

        [$from, $to] = explode('..', $value, 2);

        if ($from === '' || $to === '') {
            throw new QueryParamMalformed(
                "filter[$property][between]",
                "The 'between' operator needs both bounds; use 'gte' or 'lte' for an open range.",
            );
        }

        return [$from, $to];
    }

    public function getDescription(): array
    {
        return [
            'type' => 'RangeFilter',
            'description' => 'Bound a field between values',
            'operators' => [
                'gt' => '> (greater than)',
                'gte' => '>= (greater than or equal)',
                'lt' => '< (less than)',
                'lte' => '<= (less than or equal)',
                'between' => 'from..to (inclusive on both ends)',
            ],
            'properties' => $this->properties,
            'example' => 'filter[price][gte]=10&filter[price][lt]=100',
        ];
    }

    public function supports(string $field): bool
    {
        return in_array($field, $this->properties, true);
    }
}
