<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi;

/**
 * Helper class for serializing data to JSON:API format
 *
 * Implements the JSON:API specification for resource serialization
 * @see https://jsonapi.org/format/
 */
class JsonApiSerializer
{
    /**
     * Serialize a single resource to JSON:API format
     *
     * @param array<string, mixed> $data Resource data
     * @param string|null $type Resource type (optional, for clarity)
     * @param array<string, mixed> $meta Additional metadata (optional)
     * @param array<string, mixed> $included Included related resources (optional)
     * @return array<string, mixed>
     */
    public static function serializeResource(
        array $data,
        ?string $type = null,
        array $meta = [],
        array $included = []
    ): array {
        $response = ['data' => $data];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        if (!empty($included)) {
            $response['included'] = $included;
        }

        return $response;
    }

    /**
     * Serialize a collection of resources to JSON:API format with pagination
     *
     * @param array<int, array<string, mixed>> $data Collection of resources
     * @param int $total Total number of resources
     * @param int $currentPage Current page number
     * @param int $perPage Items per page
     * @param string|null $type Resource type (optional)
     * @param array<string, mixed> $meta Additional metadata (optional)
     * @param array<string, mixed> $included Included related resources (optional)
     * @param string|null $baseUrl Base URL for pagination links (optional)
     * @return array<string, mixed>
     */
    public static function serializeCollection(
        array $data,
        int $total,
        int $currentPage = 1,
        int $perPage = 25,
        ?string $type = null,
        array $meta = [],
        array $included = [],
        ?string $baseUrl = null
    ): array {
        $lastPage = (int)ceil($total / $perPage);
        $from = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
        $to = min($currentPage * $perPage, $total);

        $response = [
            'data' => $data,
            'meta' => array_merge([
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ], $meta),
        ];

        // Add pagination links if base URL provided
        if ($baseUrl !== null) {
            $response['links'] = self::buildPaginationLinks(
                $baseUrl,
                $currentPage,
                $lastPage,
                $perPage
            );
        }

        if (!empty($included)) {
            $response['included'] = $included;
        }

        return $response;
    }

    /**
     * Build JSON:API compliant pagination links
     *
     * @param string $baseUrl Base URL for links
     * @param int $currentPage Current page number
     * @param int $lastPage Last page number
     * @param int $perPage Items per page
     * @return array<string, string|null>
     */
    private static function buildPaginationLinks(
        string $baseUrl,
        int $currentPage,
        int $lastPage,
        int $perPage
    ): array {
        $buildUrl = function (int $page) use ($baseUrl, $perPage): string {
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            return "{$baseUrl}{$separator}page[number]={$page}&page[size]={$perPage}";
        };

        return [
            'first' => $buildUrl(1),
            'last' => $buildUrl($lastPage),
            'prev' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
            'next' => $currentPage < $lastPage ? $buildUrl($currentPage + 1) : null,
            'self' => $buildUrl($currentPage),
        ];
    }

    /**
     * Parse JSON:API pagination parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array{number: int, size: int}
     */
    public static function parsePaginationParams(array $queryParams): array
    {
        // Support both JSON:API format (page[number], page[size])
        // and legacy format (page, per_page) for backward compatibility
        $pageNumber = 1;
        $pageSize = 25;

        if (isset($queryParams['page'])) {
            if (is_array($queryParams['page'])) {
                // JSON:API format: page[number] and page[size]
                $pageNumber = isset($queryParams['page']['number'])
                    ? (int)$queryParams['page']['number']
                    : 1;
                $pageSize = isset($queryParams['page']['size'])
                    ? (int)$queryParams['page']['size']
                    : 25;
            } else {
                // Legacy format: page
                $pageNumber = (int)$queryParams['page'];
            }
        }

        // Legacy format: per_page
        if (isset($queryParams['per_page'])) {
            $pageSize = (int)$queryParams['per_page'];
        }

        // Ensure sensible limits
        $pageNumber = max(1, $pageNumber);
        $pageSize = max(1, min(100, $pageSize)); // Cap at 100 items per page

        return [
            'number' => $pageNumber,
            'size' => $pageSize,
        ];
    }

    /**
     * Parse JSON:API filter parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array<string, mixed>
     */
    public static function parseFilterParams(array $queryParams): array
    {
        $filters = [];

        if (!isset($queryParams['filter']) || !is_array($queryParams['filter'])) {
            return $filters;
        }

        foreach ($queryParams['filter'] as $key => $value) {
            // Handle nested filter operators (e.g., filter[created_at][gt]=2024-01-01)
            if (is_array($value)) {
                $filters[$key] = $value;
            } else {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * Parse JSON:API sort parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array<string, string> Associative array of field => direction
     */
    public static function parseSortParams(array $queryParams): array
    {
        if (!isset($queryParams['sort']) || !is_string($queryParams['sort'])) {
            return [];
        }

        $sortFields = explode(',', $queryParams['sort']);
        $result = [];

        foreach ($sortFields as $field) {
            $field = trim($field);
            if (str_starts_with($field, '-')) {
                $result[substr($field, 1)] = 'DESC';
            } else {
                $result[$field] = 'ASC';
            }
        }

        return $result;
    }

    /**
     * Parse JSON:API include parameters from query params
     *
     * @param array<string, mixed> $queryParams Query parameters
     * @return array<int, string>
     */
    public static function parseIncludeParams(array $queryParams): array
    {
        if (!isset($queryParams['include']) || !is_string($queryParams['include'])) {
            return [];
        }

        return array_map('trim', explode(',', $queryParams['include']));
    }

    /**
     * Create an error response in JSON:API format
     *
     * @param string $title Error title
     * @param string $detail Error detail
     * @param int $status HTTP status code
     * @param array<string, mixed> $meta Additional metadata
     * @return array<string, mixed>
     */
    public static function serializeError(
        string $title,
        string $detail,
        int $status = 400,
        array $meta = []
    ): array {
        $error = [
            'status' => (string)$status,
            'title' => $title,
            'detail' => $detail,
        ];

        if (!empty($meta)) {
            $error['meta'] = $meta;
        }

        return ['errors' => [$error]];
    }

    /**
     * Create validation errors response in JSON:API format
     *
     * @param array<string, string> $validationErrors Field => error message
     * @return array<string, mixed>
     */
    public static function serializeValidationErrors(array $validationErrors): array
    {
        $errors = [];

        foreach ($validationErrors as $field => $message) {
            $errors[] = [
                'status' => '422',
                'title' => 'Validation Error',
                'detail' => $message,
                'source' => [
                    'pointer' => '/data/attributes/' . $field,
                ],
            ];
        }

        return ['errors' => $errors];
    }
}
