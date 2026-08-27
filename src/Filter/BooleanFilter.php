<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Modufolio\JsonApi\Exception\QueryParamMalformed;

/**
 * Boolean Filter for JSON:API
 *
 * Matches a boolean column against a value from the query string, which is
 * always a string: `filter[active]=true` arrives as `'true'`, and comparing
 * that to a boolean column is a type error on PostgreSQL and a silent
 * mismatch elsewhere.
 *
 * The engines disagree on what a boolean even is — PostgreSQL a real boolean,
 * MySQL a TINYINT, SQL Server a BIT, SQLite an integer — so the value is bound
 * as {@see ParameterType::BOOLEAN} and each driver renders its own
 * representation. Binding a plain PHP `false` instead is a trap worth naming:
 * pdo_sqlite sends it as an empty string, which matches no row and reports
 * success.
 *
 * Example usage:
 * GET /api/contacts?filter[active]=true
 * GET /api/contacts?filter[active]=0
 */
class BooleanFilter implements FilterInterface
{
    /**
     * @param array<string> $properties List of boolean fields to filter on
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

            // An operator map (filter[active][eq]=true) belongs to the
            // comparison handler; this filter answers the plain form.
            if (is_array($value)) {
                continue;
            }

            $column = $fieldMappings[$property]['columnName'] ?? $property;
            $paramName = "bool_{$property}_{$paramCounter}";

            $qb->andWhere("$alias.$column = :$paramName");

            // Bound here, with its type, rather than handed back for the
            // caller to bind: the generic binding pass sets values without a
            // type, and an untyped bool is the very thing that breaks.
            $qb->setParameter($paramName, $this->asBool($property, $value), ParameterType::BOOLEAN);
            $paramCounter++;
        }

        return $bindings;
    }

    /**
     * Anything unrecognised is a malformed request. Casting it silently would
     * turn a typo into the complete opposite result set, reported as success.
     */
    private function asBool(string $property, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = is_string($value) ? strtolower(trim($value)) : $value;

        return match ($normalised) {
            'true', '1', 1, 'yes', 'on' => true,
            'false', '0', 0, 'no', 'off' => false,
            default => throw new QueryParamMalformed(
                "filter[$property]",
                "Expected a boolean; 'true' or 'false'.",
            ),
        };
    }

    public function getDescription(): array
    {
        return [
            'type' => 'BooleanFilter',
            'description' => 'Filter resources by a boolean field',
            'operators' => [
                '' => 'true / false (also 1, 0, yes, no, on, off)',
            ],
            'properties' => $this->properties,
            'example' => 'filter[active]=true',
        ];
    }

    public function supports(string $field): bool
    {
        return in_array($field, $this->properties, true);
    }
}
