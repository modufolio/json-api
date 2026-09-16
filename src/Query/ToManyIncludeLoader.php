<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Modufolio\JsonApi\Exception\InclusionUnrecognized;

/**
 * Fetches to-many related rows for a set of parent ids with one batched
 * IN-query per relationship, after the main query has run.
 *
 * Every OneToMany and ManyToMany the resource exposes is queried for its ids,
 * so relationship linkage appears whether or not it was included; only the
 * relationships named in `?include=` have their full fields read. A second
 * path segment (`comments.author`) joins a to-one of the included resource
 * onto the same query.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class ToManyIncludeLoader
{
    public function __construct(
        private readonly Connection $conn,
        private readonly SchemaRegistry $schemas,
        private readonly ResourceSchema $root,
        private readonly RowTransformer $transformer,
    ) {
    }

    /**
     * @param array<array-key, int|string|null> $parentIds
     * @param list<string>                      $includes
     * @param array<string, list<string>>       $sparseFields
     */
    public function load(array $parentIds, array $includes, array $sparseFields): ToManyIncludes
    {
        if (empty($parentIds)) {
            return ToManyIncludes::none();
        }

        [$requested, $nested] = $this->parseIncludes($includes);

        $rootMeta = $this->root->metadata();
        $linkage = [];
        $included = [];

        foreach ($this->root->allowedRelationships() as $segment) {
            if (!$rootMeta->hasAssociation($segment)) {
                continue;
            }

            $mapping = $rootMeta->getAssociationMapping($segment);

            // TO_ONE is resolved by a join in the main query.
            if ($mapping['type'] & ClassMetadata::TO_ONE) {
                continue;
            }

            $manyToMany = $this->manyToManyJoin($mapping);

            // OneToMany needs the inverse side to know its foreign key;
            // ManyToMany carries its join table instead.
            $mappedBy = $mapping['mappedBy'] ?? null;

            if ($manyToMany === null && !$mappedBy) {
                continue;
            }

            $target = $this->schemas->of($mapping['targetEntity']);
            $targetMeta = $target->metadata();
            $targetKey = $target->resourceKey();

            if ($manyToMany === null) {
                $inverseMapping = $targetMeta->getAssociationMapping($mappedBy);
                $fkColumn = $inverseMapping['joinColumns'][0]['name'] ?? $mappedBy . '_id';
            } else {
                $fkColumn = $manyToMany['parentColumn'];
            }

            // Always need id; only fetch full fields when this rel is in ?include
            $isIncluded = isset($requested[$segment]);

            $selectParts = $isIncluded
                ? $this->includedColumns($target, $sparseFields)
                : [$this->conn->quoteIdentifier('id')];

            [$nestedJoins, $nestedSelects] = $isIncluded
                ? $this->nestedToOne($segment, $target, $nested[$segment] ?? [], $sparseFields)
                : [[], []];

            $sql = $this->sql($target->tableName(), $fkColumn, $manyToMany, $selectParts, $nestedJoins, $nestedSelects, count($parentIds));

            $bindings = [];
            foreach (array_values($parentIds) as $i => $pid) {
                $bindings['tm_' . $i] = $pid;
            }

            $rows = $this->conn->executeQuery($sql, $bindings)->fetchAllAssociative();

            foreach ($rows as $row) {
                $parentId = $row['__parent_id'];
                unset($row['__parent_id']);
                $linkage[$segment][$parentId][] = ['type' => $targetKey, 'id' => (string) $row['id']];
                if ($isIncluded) {
                    $included[$segment][$parentId][] = $this->transformer->included($row, $target);
                }
            }
        }

        return new ToManyIncludes($linkage, $included);
    }

    /**
     * Split include paths into the relationships requested on the root and
     * the to-one names nested under each of them.
     *
     * @param list<string> $includes
     *
     * @return array{array<string, true>, array<string, list<string>>}
     */
    private function parseIncludes(array $includes): array
    {
        $requested = [];
        $nested = [];

        foreach ($includes as $path) {
            $segments = explode('.', $path);
            $requested[$segments[0]] = true;

            // A second segment names a to-one on the included resource — the
            // `comments.author` shape from the docs.
            if (isset($segments[1])) {
                // Only one level of nesting is resolved. Truncating a deeper
                // path silently would return a result that looks complete but
                // is missing what was asked for.
                if (isset($segments[2])) {
                    throw new InclusionUnrecognized(
                        $path,
                        "Include path $path nests too deeply; only one level "
                        . 'of nesting (rel.toOneRel) is supported.'
                    );
                }

                $nested[$segments[0]][] = $segments[1];
            }
        }

        return [$requested, $nested];
    }

    /**
     * The columns to read off an included row: id, its selectable fields, and
     * the foreign keys of its own to-one relationships for linkage.
     *
     * @param array<string, list<string>> $sparseFields
     *
     * @return list<string> Quoted column expressions, unqualified.
     */
    private function includedColumns(ResourceSchema $target, array $sparseFields): array
    {
        $targetMeta = $target->metadata();
        $parts = [$this->conn->quoteIdentifier('id')];

        foreach ($target->selectableFields($sparseFields) as $field) {
            $column = $target->columnName($field);
            if ($column !== 'id') {
                $parts[] = $this->conn->quoteIdentifier($column);
            }
        }

        foreach ($target->allowedRelationships() as $rel) {
            if (!$targetMeta->hasAssociation($rel)) {
                continue;
            }
            $relMapping = $targetMeta->getAssociationMapping($rel);
            if (($relMapping['type'] & ClassMetadata::TO_ONE) && isset($relMapping['joinColumns'])) {
                $relFk = $relMapping['joinColumns'][0]['name'] ?? $rel . '_id';
                $parts[] = $this->conn->quoteIdentifier($relFk) . ' AS _rel_' . $rel . '_id';
            }
        }

        return $parts;
    }

    /**
     * Nested `rel.subRel` — join the to-one named by the second segment and
     * select its columns onto the included row, so a caller can read a
     * related record's fields rather than only its foreign key.
     *
     * @param list<string>                $subSegments
     * @param array<string, list<string>> $sparseFields
     *
     * @return array{list<string>, list<string>} Join fragments (with a `%s` for the target table) and select expressions.
     */
    private function nestedToOne(string $segment, ResourceSchema $target, array $subSegments, array $sparseFields): array
    {
        $targetMeta = $target->metadata();
        $joins = [];
        $selects = [];
        $n = 0;

        foreach ($subSegments as $subSegment) {
            if (!$targetMeta->hasAssociation($subSegment)) {
                throw new InclusionUnrecognized(
                    "$segment.$subSegment",
                    "Unknown include: $subSegment in path $segment.$subSegment"
                );
            }

            $subMapping = $targetMeta->getAssociationMapping($subSegment);

            // Only to-one nests here. A to-many under a to-many would need
            // its own batched query per parent set; rejecting it is better
            // than silently returning nothing.
            if (!($subMapping['type'] & ClassMetadata::TO_ONE) || !isset($subMapping['joinColumns'])) {
                throw new InclusionUnrecognized(
                    "$segment.$subSegment",
                    "Nested include $segment.$subSegment is not a to-one relationship; "
                    . 'only to-one nesting is supported.'
                );
            }

            $sub = $this->schemas->of($subMapping['targetEntity']);
            $subAlias = 'n' . $n++;
            $subFk = $subMapping['joinColumns'][0]['name'] ?? $subSegment . '_id';
            $subReferenced = $subMapping['joinColumns'][0]['referencedColumnName'] ?? 'id';

            $joins[] = sprintf(
                'LEFT JOIN %s %s ON %%s.%s = %s.%s',
                $this->conn->quoteIdentifier($sub->tableName()),
                $subAlias,
                $this->conn->quoteIdentifier($subFk),
                $subAlias,
                $this->conn->quoteIdentifier($subReferenced)
            );

            foreach ($sub->selectableFields($sparseFields) as $subField) {
                $selects[] = sprintf(
                    '%s.%s AS %s_%s',
                    $subAlias,
                    $this->conn->quoteIdentifier($sub->columnName($subField)),
                    $subSegment,
                    $subField
                );
            }
        }

        return [$joins, $selects];
    }

    /**
     * @param array{joinTable: string, parentColumn: string, targetColumn: string}|null $manyToMany
     * @param list<string> $selectParts
     * @param list<string> $nestedJoins
     * @param list<string> $nestedSelects
     */
    private function sql(
        string $targetTable,
        string $fkColumn,
        ?array $manyToMany,
        array $selectParts,
        array $nestedJoins,
        array $nestedSelects,
        int $parentCount,
    ): string {
        $quotedFk = $this->conn->quoteIdentifier($fkColumn);
        $quotedTarget = $this->conn->quoteIdentifier($targetTable);

        // Qualify the target's own columns in every case: a nested join or
        // the ManyToMany join table can otherwise make `id` ambiguous.
        // Each part is either `"col"` or `"col" AS alias`, and the table
        // prefix is correct for both since the alias trails the column.
        $qualified = array_map(
            static fn (string $part): string => $quotedTarget . '.' . $part,
            $selectParts
        );
        $qualified = array_merge($qualified, $nestedSelects);

        $joinSql = '';
        foreach ($nestedJoins as $nestedJoin) {
            $joinSql .= ' ' . sprintf($nestedJoin, $quotedTarget);
        }

        $placeholders = [];
        for ($i = 0; $i < $parentCount; $i++) {
            $placeholders[] = ':tm_' . $i;
        }

        if ($manyToMany === null) {
            // OneToMany: the foreign key lives on the target row itself.
            return sprintf(
                'SELECT %s, %s.%s AS __parent_id FROM %s%s WHERE %s.%s IN (%s)',
                implode(', ', $qualified),
                $quotedTarget,
                $quotedFk,
                $quotedTarget,
                $joinSql,
                $quotedTarget,
                $quotedFk,
                implode(', ', $placeholders)
            );
        }

        // ManyToMany: the owning row is reached through the join table,
        // which supplies the parent id.
        $joinTable = $this->conn->quoteIdentifier($manyToMany['joinTable']);
        $quotedTargetColumn = $this->conn->quoteIdentifier($manyToMany['targetColumn']);

        return sprintf(
            'SELECT %s, %s.%s AS __parent_id FROM %s INNER JOIN %s ON %s.%s = %s.id%s WHERE %s.%s IN (%s)',
            implode(', ', $qualified),
            $joinTable,
            $quotedFk,
            $quotedTarget,
            $joinTable,
            $joinTable,
            $quotedTargetColumn,
            $quotedTarget,
            $joinSql,
            $joinTable,
            $quotedFk,
            implode(', ', $placeholders)
        );
    }

    /**
     * Join-table coordinates for a ManyToMany association, or null when the
     * mapping is not ManyToMany.
     *
     * The join table is declared on the owning side only, so an inverse-side
     * mapping is resolved by reading it back off the target entity — and its
     * two columns then swap roles, because "parent" and "target" are relative
     * to the side being queried.
     *
     * Accepts whatever getAssociationMapping() returns — an array on older
     * Doctrine, an ArrayAccess mapping object on ORM 3 — and only ever reads
     * it by key, as the rest of this namespace does.
     *
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $mapping
     *
     * @return array{joinTable: string, parentColumn: string, targetColumn: string}|null
     */
    private function manyToManyJoin(array|\ArrayAccess $mapping): ?array
    {
        if (!($mapping['type'] & ClassMetadata::MANY_TO_MANY)) {
            return null;
        }

        if (isset($mapping['joinTable'])) {
            $joinTable = $mapping['joinTable'];

            return [
                'joinTable'    => $joinTable['name'],
                'parentColumn' => $joinTable['joinColumns'][0]['name'],
                'targetColumn' => $joinTable['inverseJoinColumns'][0]['name'],
            ];
        }

        $mappedBy = $mapping['mappedBy'] ?? null;

        if ($mappedBy === null) {
            return null;
        }

        $targetMeta = $this->schemas->of($mapping['targetEntity'])->metadata();

        if (!$targetMeta->hasAssociation($mappedBy)) {
            return null;
        }

        $owning = $targetMeta->getAssociationMapping($mappedBy);

        if (!isset($owning['joinTable'])) {
            return null;
        }

        $joinTable = $owning['joinTable'];

        return [
            'joinTable'    => $joinTable['name'],
            // Mirrored: from this side, the owning side's inverse column is
            // the one holding our parent ids.
            'parentColumn' => $joinTable['inverseJoinColumns'][0]['name'],
            'targetColumn' => $joinTable['joinColumns'][0]['name'],
        ];
    }
}
