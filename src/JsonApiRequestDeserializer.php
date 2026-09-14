<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use InvalidArgumentException;
use Modufolio\JsonApi\Exception\LidUnresolved;
use Modufolio\JsonApi\Exception\ResourceTypeConflict;

/**
 * Deserializes JSON:API request payloads
 *
 * Handles the JSON:API document structure for create/update operations:
 * - Validates document structure
 * - Extracts attributes
 * - Extracts and normalizes relationships
 * - Validates resource type
 * - Resolves JSON:API 1.1 local identifiers (`lid`) in relationship linkage
 */
class JsonApiRequestDeserializer
{
    /**
     * Deserialize a JSON:API request payload
     *
     * The result carries the primary resource's `id` and `lid` (each null
     * when absent) beside its attributes and relationships. A relationship
     * identifier may name a `lid` instead of an `id`; it is resolved through
     * `$lids`, which an atomic operations processor fills as it creates
     * resources. Without a registry — a plain `POST` — a `lid` reference has
     * nothing to resolve against and is rejected.
     *
     * @param array<string, mixed> $payload The decoded JSON payload
     * @param string $expectedType The expected resource type
     * @param bool $requireType Whether to require and validate the type field
     * @param LidRegistry|null $lids Local identifiers already assigned in this request
     * @return array{attributes: array<string, mixed>, relationships: array<string, mixed>, id: string|null, lid: string|null}
     * @throws InvalidArgumentException If the payload is invalid
     * @throws LidUnresolved If a relationship names a `lid` the registry does not know
     */
    public function deserialize(array $payload, string $expectedType, bool $requireType = true, ?LidRegistry $lids = null): array
    {
        // Validate top-level structure
        if (!isset($payload['data'])) {
            throw new InvalidArgumentException('JSON:API request must have a "data" member');
        }

        $data = $payload['data'];

        if (!is_array($data)) {
            throw new InvalidArgumentException('JSON:API "data" member must be an object');
        }

        // Validate type if required. Both failures are a 409: the body is not
        // malformed, it addresses a different collection than the endpoint.
        if ($requireType) {
            if (!isset($data['type'])) {
                throw new ResourceTypeConflict(
                    $expectedType,
                    null,
                    'JSON:API resource object must have a "type" member'
                );
            }

            if ($data['type'] !== $expectedType) {
                $actual = is_string($data['type']) ? $data['type'] : null;
                throw new ResourceTypeConflict(
                    $expectedType,
                    $actual,
                    sprintf('Expected resource type "%s", got "%s"', $expectedType, $actual ?? gettype($data['type']))
                );
            }
        }

        // Extract attributes
        $attributes = $data['attributes'] ?? [];

        if (!is_array($attributes)) {
            throw new InvalidArgumentException('JSON:API "attributes" member must be an object');
        }

        // Extract and normalize relationships
        $relationships = [];

        if (isset($data['relationships'])) {
            if (!is_array($data['relationships'])) {
                throw new InvalidArgumentException('JSON:API "relationships" member must be an object');
            }

            foreach ($data['relationships'] as $relationshipName => $relationshipData) {
                if (!is_array($relationshipData)) {
                    throw new InvalidArgumentException(
                        sprintf('Relationship "%s" must be an object', $relationshipName)
                    );
                }
                $relationships[$relationshipName] = $this->normalizeRelationship($relationshipData, (string) $relationshipName, $lids);
            }
        }

        $id = $data['id'] ?? null;
        $lid = $data['lid'] ?? null;

        if ($id !== null && !is_scalar($id)) {
            throw new InvalidArgumentException('JSON:API "id" member must be a string');
        }
        if ($lid !== null && !is_scalar($lid)) {
            throw new InvalidArgumentException('JSON:API "lid" member must be a string');
        }

        return [
            'attributes' => $attributes,
            'relationships' => $relationships,
            'id' => $id === null ? null : (string) $id,
            'lid' => $lid === null ? null : (string) $lid,
        ];
    }

    /**
     * Normalize a relationship object to extract the ID(s)
     *
     * Handles both to-one and to-many relationships:
     * - To-one: {"data": {"type": "account", "id": "5"}} => 5
     * - To-many: {"data": [{"type": "tag", "id": "1"}, {"type": "tag", "id": "2"}]} => [1, 2]
     * - Null: {"data": null} => null
     *
     * A resource identifier may carry a `lid` instead of an `id` (JSON:API
     * 1.1); it is resolved through `$lids` to the id the server assigned.
     *
     * @param array<string, mixed> $relationshipData The relationship object
     * @param string $relationshipName The relationship name (for error messages)
     * @return int|array<int, int|string>|null The normalized relationship ID(s)
     * @throws InvalidArgumentException If the relationship format is invalid
     */
    private function normalizeRelationship(array $relationshipData, string $relationshipName, ?LidRegistry $lids): int|array|null
    {
        // array_key_exists, not isset: JSON:API allows `data: null` to clear a
        // to-one relationship. isset() treats a present-but-null value as
        // absent, which both rejected a valid null relationship and made the
        // `$data === null` handling below unreachable.
        if (!array_key_exists('data', $relationshipData)) {
            throw new InvalidArgumentException(
                sprintf('Relationship "%s" must have a "data" member', $relationshipName)
            );
        }

        $data = $relationshipData['data'];

        // Handle null relationship
        if ($data === null) {
            return null;
        }

        // Handle to-many relationship (array of resource identifiers)
        if (is_array($data) && array_is_list($data)) {
            $ids = [];
            foreach ($data as $index => $resourceIdentifier) {
                if (!is_array($resourceIdentifier)) {
                    throw new InvalidArgumentException(
                        sprintf('Relationship "%s" array item at index %d must be an object', $relationshipName, $index)
                    );
                }

                $ids[] = $this->normalizeId($this->identifierId(
                    $resourceIdentifier,
                    $relationshipName,
                    $lids,
                    "/data/relationships/$relationshipName/data/$index",
                ));
            }
            return $ids;
        }

        // Handle to-one relationship (single resource identifier)
        if (is_array($data)) {
            return $this->normalizeId($this->identifierId(
                $data,
                $relationshipName,
                $lids,
                "/data/relationships/$relationshipName/data",
            ));
        }

        throw new InvalidArgumentException(
            sprintf('Relationship "%s" data must be null, an object, or an array', $relationshipName)
        );
    }

    /**
     * The id a resource identifier object refers to.
     *
     * `{"type", "id"}` is the id itself. `{"type", "lid"}` names a resource
     * created earlier in the same request; the registry says which id it got.
     *
     * @param array<string, mixed> $identifier
     */
    private function identifierId(array $identifier, string $relationshipName, ?LidRegistry $lids, string $pointer): mixed
    {
        if (!isset($identifier['type'])) {
            throw new InvalidArgumentException(
                sprintf('Relationship "%s" resource identifier must have a "type" member', $relationshipName)
            );
        }

        if (isset($identifier['id'])) {
            return $identifier['id'];
        }

        if (isset($identifier['lid'])) {
            if ($lids === null) {
                throw new LidUnresolved((string) $identifier['type'], (string) $identifier['lid'], $pointer);
            }

            return $lids->resolveIdentifier($identifier, $pointer);
        }

        throw new InvalidArgumentException(
            sprintf('Relationship "%s" resource identifier must have "type" and "id" (or "lid") members', $relationshipName)
        );
    }

    /**
     * Normalize an ID to an integer
     *
     * JSON:API allows IDs to be strings, but Doctrine typically uses integers
     *
     * @param mixed $id The ID value
     * @return int The normalized ID
     */
    private function normalizeId(mixed $id): int
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && is_numeric($id)) {
            return (int)$id;
        }

        if (is_numeric($id)) {
            return (int)$id;
        }

        throw new InvalidArgumentException(
            sprintf('Resource ID must be numeric, got "%s"', gettype($id))
        );
    }

    /**
     * Merge attributes and relationships into a single array for entity population
     *
     * This is a convenience method that combines attributes and relationships
     * into a single array that can be passed to populateEntity()
     *
     * @param array<string, mixed> $attributes The attributes array
     * @param array<string, mixed> $relationships The relationships array (normalized IDs)
     * @return array<string, mixed> The merged data
     */
    public function mergeData(array $attributes, array $relationships): array
    {
        return array_merge($attributes, $relationships);
    }
}
