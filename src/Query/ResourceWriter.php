<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;

/**
 * Builds the INSERT, UPDATE and DELETE statements for one resource.
 *
 * Field-keyed request data is mapped through the allow-list to columns, the
 * timestamps the table carries are stamped, and the row scope is applied —
 * forced onto a new row, required of an updated or deleted one. The caller
 * decides whether to execute the statement or only show it.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class ResourceWriter
{
    public function __construct(
        private readonly Connection $conn,
        private readonly ResourceSchema $schema,
        private readonly RowScope $scope,
    ) {
    }

    /**
     * @param array<string, mixed> $data Field-keyed attributes and to-one ids.
     */
    public function insert(array $data): QueryBuilder
    {
        $columns = $this->writableColumns($data);
        $columns = $this->stamp($columns, ['created_at', 'updated_at']);

        // A scoped create must produce a row inside the scope — otherwise a
        // caller could create records they can neither see nor touch again
        // (or worse, park them in another tenant).
        $columns = $this->scope->applyToCreateData($columns);

        $qb = $this->conn->createQueryBuilder()->insert($this->schema->tableName());
        foreach ($columns as $column => $value) {
            $qb->setValue($column, ':' . $column);
            $qb->setParameter($column, $value);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $data Field-keyed attributes and to-one ids.
     */
    public function update(string $id, array $data): QueryBuilder
    {
        $columns = $this->writableColumns($data);
        $columns = $this->stamp($columns, ['updated_at']);

        $qb = $this->conn->createQueryBuilder()->update($this->schema->tableName());
        foreach ($columns as $column => $value) {
            $qb->set($column, ':' . $column);
            $qb->setParameter($column, $value);
        }
        $qb->where('id = :id')->setParameter('id', $id);

        // The scope guards writes exactly like reads: a row outside it is left
        // untouched, and the caller's scoped re-read then reports it exactly
        // like a missing one, so an out-of-scope id cannot be told apart from
        // a nonexistent one.
        return $this->constrainToScope($qb);
    }

    public function delete(string $id): QueryBuilder
    {
        $qb = $this->conn->createQueryBuilder()->delete($this->schema->tableName());
        $qb->where('id = :id')->setParameter('id', $id);

        // Same containment as update: a row outside the scope is not deleted.
        return $this->constrainToScope($qb);
    }

    private function constrainToScope(QueryBuilder $qb): QueryBuilder
    {
        $scope = $this->scope->conditions(null);
        foreach ($scope['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($scope['bindings'] as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return $qb;
    }

    /**
     * Set each of `$timestamps` to now, when the table has that column.
     *
     * @param array<string, mixed> $columns
     * @param list<string>         $timestamps
     *
     * @return array<string, mixed>
     */
    private function stamp(array $columns, array $timestamps): array
    {
        $tableColumns = array_column($this->schema->metadata()->fieldMappings, 'columnName');

        foreach ($timestamps as $timestamp) {
            if (in_array($timestamp, $tableColumns, true)) {
                $columns[$timestamp] = date('Y-m-d H:i:s');
            }
        }

        return $columns;
    }

    /**
     * The columns a create or update may write, from field-keyed data.
     *
     * Allowed fields map through their column names. Allowed to-one
     * relationships whose foreign key lives on this table map through their
     * join column, so `['organization' => 5]` — the shape
     * {@see \Modufolio\JsonApi\JsonApiRequestDeserializer} produces for a
     * to-one — writes `organization_id`. A null clears it. To-many data is
     * left out: it lives on another table (or a join table) and is not a
     * column of this row. Anything else in `$data` is dropped, so a client
     * cannot write a field it was not configured to see.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function writableColumns(array $data): array
    {
        $meta = $this->schema->metadata();
        $columns = [];

        foreach (array_intersect_key($data, array_flip($this->schema->allowedFields())) as $field => $value) {
            $columns[$this->schema->columnName($field)] = $value;
        }

        foreach ($this->schema->allowedRelationships() as $relationship) {
            if (!array_key_exists($relationship, $data) || !$meta->hasAssociation($relationship)) {
                continue;
            }
            $mapping = $meta->getAssociationMapping($relationship);
            if (!($mapping['type'] & ClassMetadata::TO_ONE) || !isset($mapping['joinColumns'][0]['name'])) {
                // Silently ignoring it would let a to-many "update" succeed
                // while changing nothing — the worst kind of no-op.
                throw new InvalidArgumentException(
                    "Relationship '$relationship' is not written through this resource: only a to-one whose foreign key lives on it is."
                );
            }
            $value = $data[$relationship];
            if (is_array($value)) {
                throw new InvalidArgumentException(
                    "Relationship '$relationship' is to-one and takes a single id, not a list."
                );
            }
            $columns[$mapping['joinColumns'][0]['name']] = $value;
        }

        return $columns;
    }
}
