<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

class JsonApiUrlParser
{
    public function __construct(
        private readonly array $config
    ) {
    }

    public function parse(ServerRequestInterface $request, string $entityClass): JsonApiQueryParams
    {
        if (!isset($this->config[$entityClass])) {
            throw new InvalidArgumentException("Entity class $entityClass not found in configuration");
        }

        $queryParams = $request->getQueryParams();
        $resourceKey = $this->config[$entityClass]['resource_key'];
        $allowedFields = $this->config[$entityClass]['fields'] ?? [];
        $allowedRelationships = $this->config[$entityClass]['relationships'] ?? [];

        // Parse fields
        $fields = [];
        if (isset($queryParams['fields'][$resourceKey])) {
            $requestedFields = explode(',', $queryParams['fields'][$resourceKey]);
            $fields = array_values(array_filter(
                array_map('trim', $requestedFields),
                fn ($field) => in_array($field, $allowedFields, true)
            ));
        }

        // Parse filter with improved validation
        $filter = [];
        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            $filter = $this->parseFilters($queryParams['filter'], $allowedFields);
        }

        // Parse include
        $include = [];
        if (isset($queryParams['include'])) {
            $requestedIncludes = explode(',', $queryParams['include']);
            $include = array_values(array_filter(
                array_map('trim', $requestedIncludes),
                fn ($rel) => in_array($rel, $allowedRelationships, true)
            ));
        }

        // Parse sort
        $sort = [];
        if (isset($queryParams['sort'])) {
            $sortFields = explode(',', $queryParams['sort']);
            foreach ($sortFields as $field) {
                $field = trim($field);
                if (empty($field)) {
                    continue;
                }
                $hasDescPrefix = str_starts_with($field, '-');
                $fieldName = ltrim($field, '-');
                // Convert snake_case to camelCase for validation
                $camelFieldName = $this->snakeToCamel($fieldName);
                if (in_array($camelFieldName, $allowedFields, true)) {
                    // Store the camelCase version with prefix if needed
                    $sort[] = $hasDescPrefix ? '-' . $camelFieldName : $camelFieldName;
                }
            }
        }

        // Parse page
        $page = ['number' => 1, 'size' => 10];
        if (isset($queryParams['page']['number']) && is_numeric($queryParams['page']['number'])) {
            $page['number'] = max(1, (int)$queryParams['page']['number']);
        }
        if (isset($queryParams['page']['size']) && is_numeric($queryParams['page']['size'])) {
            $page['size'] = max(1, (int)$queryParams['page']['size']);
        }

        // Parse group (custom)
        $group = [];
        if (isset($queryParams['group'])) {
            $groupFields = is_array($queryParams['group']) ? $queryParams['group'] : explode(',', $queryParams['group']);
            $group = array_values(array_filter(
                array_map('trim', $groupFields),
                fn ($field) => in_array($field, $allowedFields, true)
            ));
        }

        // Parse having (custom)
        $having = ['query' => '', 'bindings' => []];
        if (isset($queryParams['having'])) {
            $having['query'] = $queryParams['having']['query'] ?? '';
            $having['bindings'] = $queryParams['having']['bindings'] ?? [];
            // Basic validation to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9\s,()=<>]*$/', $having['query'])) {
                $having['query'] = '';
                $having['bindings'] = [];
            }
        }

        // Parse id from route parameters (if available)
        $id = $request->getAttribute('id');

        return new JsonApiQueryParams(
            fields: $fields,
            filter: $filter,
            include: $include,
            sort: $sort,
            page: $page,
            group: $group,
            having: $having,
            id: $id
        );
    }

    /**
     * Parse and validate filters
     *
     * Handles both simple and complex filter formats:
     * - filter[field]=value
     * - filter[field][operator]=value
     * - filter[field]=value1&filter[field]=value2 (converted to 'in' operator)
     *
     * @param array $filters Raw filter array from query params
     * @param array $allowedFields List of allowed field names
     * @return array Validated filter array
     */
    private function parseFilters(array $filters, array $allowedFields): array
    {
        $validatedFilters = [];

        foreach ($filters as $key => $value) {
            // Skip non-string keys (malformed filters)
            if (!is_string($key)) {
                continue;
            }

            // Skip fields that are not in the allowed list
            if (!in_array($key, $allowedFields, true)) {
                continue;
            }

            // Handle array values
            if (is_array($value)) {
                // Check if this is an indexed array (numeric keys) - typical for GET_MANY requests
                // e.g., filter[id]=1&filter[id]=2&filter[id]=3 becomes ['id' => [0 => '1', 1 => '2', 2 => '3']]
                if (array_is_list($value)) {
                    // Convert to 'in' operator format for compatibility with ra-jsonapi-client
                    $validatedFilters[$key] = ['in' => array_values($value)];
                } else {
                    // Array with string keys (operators like gte, lte, in, etc.)
                    $validOperators = $this->validateOperators($value);
                    if (!empty($validOperators)) {
                        $validatedFilters[$key] = $validOperators;
                    }
                }
            } else {
                // Simple equality filter
                $validatedFilters[$key] = $value;
            }
        }

        return $validatedFilters;
    }

    /**
     * Validate and clean operator arrays
     *
     * @param array $operators Array of operators and values
     * @return array Validated operators
     */
    private function validateOperators(array $operators): array
    {
        $validOperators = [];
        $allowedOperators = ['eq', 'neq', 'not', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null', 'not_null'];

        foreach ($operators as $operator => $value) {
            // Skip non-string operator keys
            if (!is_string($operator)) {
                continue;
            }

            // Only allow known operators
            if (!in_array($operator, $allowedOperators, true)) {
                continue;
            }

            // Special handling for 'in' operator - ensure it's an array
            if ($operator === 'in') {
                if (is_string($value)) {
                    // Convert comma-separated string to array
                    $validOperators[$operator] = array_map('trim', explode(',', $value));
                } elseif (is_array($value)) {
                    $validOperators[$operator] = array_values($value);
                } else {
                    $validOperators[$operator] = [$value];
                }
            } else {
                $validOperators[$operator] = $value;
            }
        }

        return $validOperators;
    }

    /**
     * Convert snake_case to camelCase
     */
    private function snakeToCamel(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }
}