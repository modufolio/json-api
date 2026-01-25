<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use InvalidArgumentException;

/**
 * Deserializes JSON:API request payloads
 *
 * Handles the JSON:API document structure for create/update operations:
 * - Validates document structure
 * - Extracts attributes
 * - Extracts and normalizes relationships
 * - Validates resource type
 */
class JsonApiRequestDeserializer
{
    /**
     * Deserialize a JSON:API request payload
     *
     * @param array $payload The decoded JSON payload
     * @param string $expectedType The expected resource type
     * @param bool $requireType Whether to require and validate the type field
     * @return array ['attributes' => [...], 'relationships' => [...]]
     * @throws InvalidArgumentException If the payload is invalid
     */
    public function deserialize(array $payload, string $expectedType, bool $requireType = true): array
    {
        // Validate top-level structure
        if (!isset($payload['data'])) {
            throw new InvalidArgumentException('JSON:API request must have a "data" member');
        }

        $data = $payload['data'];

        if (!is_array($data)) {
            throw new InvalidArgumentException('JSON:API "data" member must be an object');
        }

        // Validate type if required
        if ($requireType) {
            if (!isset($data['type'])) {
                throw new InvalidArgumentException('JSON:API resource object must have a "type" member');
            }

            if ($data['type'] !== $expectedType) {
                throw new InvalidArgumentException(
                    sprintf('Expected resource type "%s", got "%s"', $expectedType, $data['type'])
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
                $relationships[$relationshipName] = $this->normalizeRelationship($relationshipData, $relationshipName);
            }
        }

        return [
            'attributes' => $attributes,
            'relationships' => $relationships,
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
     * @param array $relationshipData The relationship object
     * @param string $relationshipName The relationship name (for error messages)
     * @return int|array|null The normalized relationship ID(s)
     * @throws InvalidArgumentException If the relationship format is invalid
     */
    private function normalizeRelationship(array $relationshipData, string $relationshipName): int|array|null
    {
        if (!isset($relationshipData['data'])) {
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

                if (!isset($resourceIdentifier['type']) || !isset($resourceIdentifier['id'])) {
                    throw new InvalidArgumentException(
                        sprintf('Relationship "%s" resource identifier must have "type" and "id" members', $relationshipName)
                    );
                }

                $ids[] = $this->normalizeId($resourceIdentifier['id']);
            }
            return $ids;
        }

        // Handle to-one relationship (single resource identifier)
        if (is_array($data)) {
            if (!isset($data['type']) || !isset($data['id'])) {
                throw new InvalidArgumentException(
                    sprintf('Relationship "%s" resource identifier must have "type" and "id" members', $relationshipName)
                );
            }

            return $this->normalizeId($data['id']);
        }

        throw new InvalidArgumentException(
            sprintf('Relationship "%s" data must be null, an object, or an array', $relationshipName)
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
     * @param array $attributes The attributes array
     * @param array $relationships The relationships array (normalized IDs)
     * @return array The merged data
     */
    public function mergeData(array $attributes, array $relationships): array
    {
        return array_merge($attributes, $relationships);
    }
}
