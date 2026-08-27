<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

use Doctrine\DBAL\Query\QueryBuilder;
use Modufolio\JsonApi\Exception\QueryParamMalformed;

/**
 * Exists Filter for JSON:API
 *
 * Selects rows by whether a nullable field carries a value. `IS NULL` needs no
 * bound parameter, which is exactly why it cannot be expressed through the
 * ordinary comparison operators — `= NULL` matches nothing in every engine.
 *
 * Example usage:
 * GET /api/contacts?filter[deletedAt][exists]=false   (not soft-deleted)
 * GET /api/contacts?filter[phone][exists]=true        (has a phone number)
 */
class ExistsFilter implements FilterInterface
{
    /**
     * @param array<string> $properties List of nullable fields to test
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
        foreach ($this->properties as $property) {
            if (!isset($params[$property]) || !is_array($params[$property])) {
                continue;
            }

            if (!array_key_exists('exists', $params[$property])) {
                continue;
            }

            $column = $fieldMappings[$property]['columnName'] ?? $property;

            $qb->andWhere(
                $this->asBool($property, $params[$property]['exists'])
                    ? "$alias.$column IS NOT NULL"
                    : "$alias.$column IS NULL"
            );
        }

        // No bound parameters: an IS NULL predicate carries no value.
        return [];
    }

    /**
     * A query string has no booleans, so the usual spellings are accepted —
     * but anything else is a malformed request rather than a silent false,
     * which would quietly return the opposite set of rows.
     */
    private function asBool(string $property, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = is_string($value) ? strtolower(trim($value)) : $value;

        return match ($normalised) {
            'true', '1', 1, 'yes', 'on' => true,
            'false', '0', 0, 'no', 'off', '' => false,
            default => throw new QueryParamMalformed(
                "filter[$property][exists]",
                "The 'exists' operator takes a boolean; 'true' or 'false'.",
            ),
        };
    }

    public function getDescription(): array
    {
        return [
            'type' => 'ExistsFilter',
            'description' => 'Filter by whether a nullable field carries a value',
            'operators' => [
                'exists' => 'true (IS NOT NULL) / false (IS NULL)',
            ],
            'properties' => $this->properties,
            'example' => 'filter[deletedAt][exists]=false',
        ];
    }

    public function supports(string $field): bool
    {
        return in_array($field, $this->properties, true);
    }
}
