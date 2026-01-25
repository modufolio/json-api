<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use InvalidArgumentException;
use Negotiation\Negotiator;

/**
 * Normalizes request input from various formats (JSON:API, plain JSON)
 *
 * This normalizer supports:
 * - JSON:API format (application/vnd.api+json)
 * - Plain JSON format (application/json)
 * - Form data (application/x-www-form-urlencoded)
 *
 * Inspired by API Platform's input/output formats system
 */
class InputNormalizer
{
    private readonly JsonApiRequestDeserializer $jsonApiDeserializer;
    private readonly Negotiator $negotiator;

    private const SUPPORTED_FORMATS = [
        'application/vnd.api+json',
        'application/json',
        'application/x-www-form-urlencoded',
    ];

    public function __construct()
    {
        $this->jsonApiDeserializer = new JsonApiRequestDeserializer();
        $this->negotiator = new Negotiator();
    }

    /**
     * Normalize input data based on content type
     *
     * @param array $payload The decoded request payload
     * @param string $contentType The request Content-Type header
     * @param string $expectedResourceType The expected JSON:API resource type (for JSON:API format)
     * @return array Normalized data array with attributes and relationships
     * @throws InvalidArgumentException If the payload is invalid
     */
    public function normalize(array $payload, string $contentType, string $expectedResourceType): array
    {
        $mediaType = $this->negotiator->getBest($contentType, self::SUPPORTED_FORMATS);

        if ($mediaType === null) {
            // Fallback to plain JSON if content type is not recognized
            return $this->normalizeJson($payload);
        }

        return match ($mediaType->getType()) {
            'application/vnd.api+json' => $this->normalizeJsonApi($payload, $expectedResourceType),
            'application/json' => $this->normalizeJson($payload),
            default => $this->normalizeJson($payload),
        };
    }

    /**
     * Normalize JSON:API format
     *
     * Input:
     * {
     *   "data": {
     *     "type": "product",
     *     "attributes": {
     *       "name": "Product Name",
     *       "price": 99.99
     *     },
     *     "relationships": {
     *       "brand": {
     *         "data": {"type": "brand", "id": "5"}
     *       }
     *     }
     *   }
     * }
     *
     * Output:
     * {
     *   "attributes": {"name": "Product Name", "price": 99.99},
     *   "relationships": {"brand": 5}
     * }
     */
    private function normalizeJsonApi(array $payload, string $expectedResourceType): array
    {
        return $this->jsonApiDeserializer->deserialize($payload, $expectedResourceType, requireType: false);
    }

    /**
     * Normalize plain JSON format
     *
     * Input:
     * {
     *   "name": "Product Name",
     *   "price": 99.99,
     *   "brand_id": 5,
     *   "category_ids": [1, 2, 3]
     * }
     *
     * Output:
     * {
     *   "attributes": {"name": "Product Name", "price": 99.99},
     *   "relationships": {"brand": 5, "categories": [1, 2, 3]}
     * }
     */
    private function normalizeJson(array $payload): array
    {
        $attributes = [];
        $relationships = [];

        foreach ($payload as $key => $value) {
            // Detect relationship fields by convention
            // - Fields ending with _id are to-one relationships
            // - Fields ending with _ids are to-many relationships
            if (str_ends_with($key, '_ids') && is_array($value)) {
                // To-many relationship: category_ids => categories
                $relationshipName = substr($key, 0, -4); // Remove _ids
                $relationships[$relationshipName] = array_map('intval', $value);
            } elseif (str_ends_with($key, '_id')) {
                // To-one relationship: brand_id => brand
                $relationshipName = substr($key, 0, -3); // Remove _id
                $relationships[$relationshipName] = $value !== null ? (int)$value : null;
            } else {
                // Regular attribute
                $attributes[$key] = $value;
            }
        }

        return [
            'attributes' => $attributes,
            'relationships' => $relationships,
        ];
    }

    /**
     * Merge attributes and relationships into a single data array
     *
     * This is a convenience method that combines normalized data
     * into a single array ready for entity population
     */
    public function mergeData(array $normalizedData): array
    {
        $attributes = $normalizedData['attributes'] ?? [];
        $relationships = $normalizedData['relationships'] ?? [];

        return array_merge($attributes, $relationships);
    }

    /**
     * Detect if the input is JSON:API format
     */
    public function isJsonApiFormat(array $payload): bool
    {
        return isset($payload['data']) && is_array($payload['data']);
    }

    /**
     * Detect content type from request using negotiation
     *
     * @param string $contentTypeHeader The Content-Type header value
     * @return string Format identifier (jsonapi, json, form, multipart)
     */
    public function detectContentType(string $contentTypeHeader): string
    {
        $mediaType = $this->negotiator->getBest($contentTypeHeader, self::SUPPORTED_FORMATS);

        if ($mediaType === null) {
            return 'json'; // Default fallback
        }

        return match ($mediaType->getType()) {
            'application/vnd.api+json' => 'jsonapi',
            'application/json' => 'json',
            'application/x-www-form-urlencoded' => 'form',
            default => 'json',
        };
    }

    /**
     * Check if a content type is supported
     */
    public function isSupported(string $contentType): bool
    {
        $mediaType = $this->negotiator->getBest($contentType, self::SUPPORTED_FORMATS);
        return $mediaType !== null;
    }
}
