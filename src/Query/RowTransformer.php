<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

/**
 * Turns a selected row into the shape of a JSON:API resource object.
 *
 * A row carries the resource's own columns plus `_rel_<name>_id` foreign keys
 * that the SELECT aliased in for to-one linkage; this splits them into
 * `attributes` and `relationships`.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class RowTransformer
{
    private const REL_PREFIX = '_rel_';
    private const REL_SUFFIX = '_id';

    public function __construct(private readonly SchemaRegistry $schemas)
    {
    }

    /**
     * A row of the primary resource.
     *
     * No `type` member: the caller's serializer adds it. A null foreign key
     * becomes `{"data": null}` — JSON:API's spelling for a known to-one that
     * is currently empty; omitting the member would read as "not exposed",
     * which a null foreign key is not.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function primary(array $row, ResourceSchema $schema): array
    {
        $id = null;
        $attributes = [];
        $relationships = [];

        foreach ($row as $key => $value) {
            if ($key === 'id') {
                $id = $value;
            } elseif (($relName = $this->relationshipName($key)) !== null) {
                $relationships[$relName] = [
                    'data' => $value === null ? null : $this->linkage($schema, $relName, $value),
                ];
            } else {
                $attributes[$key] = $value;
            }
        }

        $result = ['id' => $id, 'attributes' => $attributes];

        if (!empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        return $result;
    }

    /**
     * A row of an included resource, with its `type` so the caller can build
     * identifiers from it.
     *
     * Empty and unmapped relationships are left out rather than rendered as
     * null: an included resource's linkage is a courtesy, not the primary
     * data, and a foreign key the target does not map is not an error.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function included(array $row, ResourceSchema $schema): array
    {
        $id = null;
        $attributes = [];
        $relationships = [];

        foreach ($row as $key => $value) {
            if ($key === 'id') {
                $id = $value;
            } elseif (($relName = $this->relationshipName($key)) !== null) {
                if ($value === null) {
                    continue;
                }
                try {
                    $relationships[$relName] = ['data' => $this->linkage($schema, $relName, $value)];
                } catch (\Exception) {
                    // skip unmapped / inaccessible relationships
                }
            } else {
                $attributes[$key] = $value;
            }
        }

        $result = [
            'type' => $schema->resourceKey(),
            'id' => (string) $id,
            'attributes' => $attributes,
        ];

        if (!empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        return $result;
    }

    /**
     * `_rel_organization_id` → `organization`; null for any other column.
     */
    private function relationshipName(string $column): ?string
    {
        if (!str_starts_with($column, self::REL_PREFIX)) {
            return null;
        }

        return substr($column, strlen(self::REL_PREFIX), -strlen(self::REL_SUFFIX));
    }

    /**
     * @return array{type: string, id: string}
     */
    private function linkage(ResourceSchema $schema, string $relName, mixed $id): array
    {
        $mapping = $schema->metadata()->getAssociationMapping($relName);
        $targetKey = $this->schemas->of($mapping['targetEntity'])->resourceKey($relName);

        return ['type' => $targetKey, 'id' => (string) $id];
    }
}
