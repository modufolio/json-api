<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;

/**
 * The row-level scope of a builder: which rows the caller may touch at all.
 *
 * Holds the constraints keyed by column, renders them as WHERE conditions for
 * reads and writes alike, and forces them onto a new row on create. See
 * {@see \Modufolio\JsonApi\JsonApiQueryBuilder::scope()} for the contract.
 *
 * Deliberately owned, not rebuilt, by the builder: a reset between requests
 * must keep the scope, because forgetting a security constraint on reuse
 * would fail open.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class RowScope
{
    /**
     * Constraints keyed by column name, after mapping in add().
     *
     * @var array<string, int|float|string|null|list<int|float|string>>
     */
    private array $columns = [];

    /**
     * Set by waive(): this query is deliberately unscoped. A waiver is a
     * statement about the caller's intent, so it persists like the scope.
     */
    private bool $waived = false;

    public function __construct(
        private readonly ResourceSchema $schema,
        private readonly Connection $conn,
    ) {
    }

    /**
     * Merge constraints in; later values win per key.
     *
     * @param array<string, int|float|string|bool|null|list<int|float|string|bool>> $scope
     */
    public function add(array $scope): void
    {
        foreach ($scope as $field => $value) {
            $column = $this->resolveColumn($field);

            if (is_array($value)) {
                if ($value === []) {
                    // An empty IN () matches nothing; a scope that can never
                    // match is almost certainly a bug upstream (an unresolved
                    // tenant), and silently returning nothing would mask it.
                    throw new InvalidArgumentException("Scope for '$field' is an empty list; refusing a scope that can never match.");
                }
                $value = array_map($this->normalizeScalar(...), $value);
            } elseif ($value !== null) {
                $value = $this->normalizeScalar($value);
            }

            $this->columns[$column] = $value;
        }
    }

    public function waive(): void
    {
        $this->waived = true;
    }

    /**
     * Refuse to execute when the resource is declared scoped and no value was
     * set for one of the scoped fields.
     *
     * Checked at execution rather than when add() is called, because the
     * order of the fluent calls is the caller's business — what matters is
     * the state at the moment a query would run.
     *
     * @throws InvalidArgumentException
     */
    public function assertSatisfied(): void
    {
        if ($this->waived) {
            return;
        }

        $required = $this->schema->scopedBy();
        $missing = [];

        foreach ($required as $field) {
            // Resolved the same way add() resolves it, so a declaration
            // naming an association matches a scope set on that association.
            if (!array_key_exists($this->resolveColumn($field), $this->columns)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s is declared scoped by "%s"; no scope was set for %s. '
                . 'Call scope([...]) with the caller\'s partition, or withoutScope() if this query is global on purpose.',
                $this->schema->class(),
                implode('", "', $required),
                '"' . implode('", "', $missing) . '"',
            ));
        }
    }

    /**
     * The scope as SQL conditions plus their bindings.
     *
     * @return array{conditions: list<string>, bindings: array<string, int|float|string>}
     */
    public function conditions(?string $alias): array
    {
        $conditions = [];
        $bindings = [];
        $n = 0;

        foreach ($this->columns as $column => $value) {
            $ref = ($alias !== null ? "$alias." : '') . $this->conn->quoteIdentifier($column);

            if ($value === null) {
                $conditions[] = "$ref IS NULL";
                continue;
            }

            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $item) {
                    $param = 'jsonapi_scope_' . $n++;
                    $placeholders[] = ':' . $param;
                    $bindings[$param] = $item;
                }
                $conditions[] = "$ref IN (" . implode(', ', $placeholders) . ')';
                continue;
            }

            $param = 'jsonapi_scope_' . $n++;
            $conditions[] = "$ref = :$param";
            $bindings[$param] = $value;
        }

        return ['conditions' => $conditions, 'bindings' => $bindings];
    }

    /**
     * Force the scope onto a new row's column map.
     *
     * Scalar and null entries overwrite whatever the client sent for that
     * column — the scope, not the request, decides the tenant column. A list
     * entry cannot pick a value by itself, so the client's value must already
     * be one of the allowed ones.
     *
     * @param array<string, mixed> $mappedData
     *
     * @return array<string, mixed>
     */
    public function applyToCreateData(array $mappedData): array
    {
        foreach ($this->columns as $column => $value) {
            if (is_array($value)) {
                $sent = $mappedData[$column] ?? null;
                $sent = is_bool($sent) ? (int) $sent : $sent;

                if (!is_scalar($sent)
                    || !in_array((string) $sent, array_map(strval(...), $value), true)) {
                    throw new InvalidArgumentException("Value for '$column' is outside the enforced scope.");
                }

                $mappedData[$column] = $sent;
                continue;
            }

            $mappedData[$column] = $value;
        }

        return $mappedData;
    }

    /**
     * Map a scope key (field name or to-one relationship name) to its column.
     *
     * Unknown keys throw instead of passing through: a misspelled scope key
     * that silently matched nothing — or worse, everything — would defeat the
     * constraint it was meant to enforce.
     */
    private function resolveColumn(string $field): string
    {
        $meta = $this->schema->metadata();
        $class = $this->schema->class();

        if (isset($meta->fieldMappings[$field])) {
            $column = $meta->fieldMappings[$field]['columnName'];
        } elseif ($meta->hasAssociation($field)) {
            $mapping = $meta->getAssociationMapping($field);
            if (!($mapping['type'] & ClassMetadata::TO_ONE) || !isset($mapping['joinColumns'])) {
                throw new InvalidArgumentException("Scope key '$field' is a to-many relationship; scopes constrain columns of $class itself.");
            }
            $column = $mapping['joinColumns'][0]['name'] ?? $field . '_id';
        } else {
            throw new InvalidArgumentException("Unknown scope key '$field' for $class; expected a field or to-one relationship name.");
        }

        if (!SqlGuard::isIdentifier($column)) {
            throw new InvalidArgumentException("Scope column '$column' is not a valid SQL identifier.");
        }

        return $column;
    }

    private function normalizeScalar(mixed $value): int|float|string
    {
        if (is_bool($value)) {
            return (int) $value; // engine-portable: SQLite/MySQL store bools as ints
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Scope values must be scalars, null, or lists of scalars; got ' . get_debug_type($value) . '.');
    }
}
