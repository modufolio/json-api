<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\ORM\Mapping\ClassMetadata;
use Modufolio\JsonApi\Exception\FieldUnrecognized;
use Modufolio\JsonApi\Exception\InclusionUnrecognized;

/**
 * What one entity exposes as a JSON:API resource.
 *
 * Joins the configurator's per-class entry (allow-listed fields and
 * relationships, resource key, operations, scope) with the Doctrine metadata
 * that maps those names to columns. Every other query component asks this
 * object rather than reading the raw config, so the fallbacks for a class
 * without a config entry live in one place.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class ResourceSchema
{
    /**
     * @param array<string, mixed>  $config The full configurator output, every class.
     * @param class-string          $class
     * @param ClassMetadata<object> $meta
     */
    public function __construct(
        private readonly array $config,
        private readonly string $class,
        private readonly ClassMetadata $meta,
    ) {
    }

    /** @return class-string */
    public function class(): string
    {
        return $this->class;
    }

    /** @return ClassMetadata<object> */
    public function metadata(): ClassMetadata
    {
        return $this->meta;
    }

    public function tableName(): string
    {
        return $this->meta->getTableName();
    }

    /**
     * The configured resource key, or null when the class has none.
     */
    public function configuredResourceKey(): ?string
    {
        $key = $this->entry('resource_key');

        return is_string($key) ? $key : null;
    }

    /**
     * The resource key, falling back to `$fallback` or — absent that — the
     * lower-cased short class name.
     */
    public function resourceKey(?string $fallback = null): string
    {
        return $this->configuredResourceKey()
            ?? $fallback
            ?? strtolower(substr($this->class, strrpos($this->class, '\\') + 1));
    }

    /** @return list<string> */
    public function allowedFields(): array
    {
        return $this->entry('fields') ?? $this->meta->getFieldNames();
    }

    /** @return list<string> */
    public function allowedRelationships(): array
    {
        $relationships = $this->entry('relationships') ?? array_keys($this->meta->getAssociationNames());

        // Both shapes are accepted: ['rel1', 'rel2'] and ['rel1' => [...], 'rel2' => [...]].
        if (!empty($relationships) && is_array(reset($relationships))) {
            $relationships = array_keys($relationships);
        }

        // A numeric-looking relationship name arrives as an int key; the
        // metadata lookups downstream take a string.
        return array_map(strval(...), array_values($relationships));
    }

    /** @return array<string, bool> */
    public function allowedOperations(): array
    {
        return $this->entry('operations') ?? ['index' => true];
    }

    /**
     * Fields a caller must supply a scope for before executing.
     *
     * @return list<string>
     */
    public function scopedBy(): array
    {
        return $this->entry('scope_by') ?? [];
    }

    public function columnName(string $field): string
    {
        return $this->meta->fieldMappings[$field]['columnName'] ?? $field;
    }

    /**
     * The fields to read when this resource is included: its allow-list,
     * narrowed by a sparse fieldset for its type when one was supplied.
     *
     * @param array<string, list<string>> $sparseFields
     *
     * @return list<string>
     */
    public function selectableFields(array $sparseFields): array
    {
        $allowed = $this->allowedFields();
        $resourceKey = $this->configuredResourceKey();

        if ($resourceKey !== null && isset($sparseFields[$resourceKey])) {
            $requested = array_intersect($allowed, (array) $sparseFields[$resourceKey]);

            // An empty intersection means the caller asked only for fields this
            // resource does not expose; the allow-list wins over the request.
            if ($requested !== []) {
                return array_values($requested);
            }
        }

        return $allowed;
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @throws FieldUnrecognized
     */
    public function assertFields(array $fields): void
    {
        $invalid = array_diff($fields, $this->allowedFields());

        if ($invalid) {
            throw new FieldUnrecognized(array_values($invalid));
        }
    }

    /**
     * @throws InclusionUnrecognized
     */
    public function assertRelationship(string $relationship): void
    {
        if (!in_array($relationship, $this->allowedRelationships())) {
            throw new InclusionUnrecognized($relationship, "Invalid relationship: $relationship");
        }
    }

    private function entry(string $key): mixed
    {
        return $this->config[$this->class][$key] ?? null;
    }
}
