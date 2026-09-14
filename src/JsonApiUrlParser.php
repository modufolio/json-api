<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi;

use InvalidArgumentException;
use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Psr\Http\Message\ServerRequestInterface;

class JsonApiUrlParser
{
    /**
     * The query parameter families the specification defines.
     */
    public const SPEC_QUERY_PARAMS = ['fields', 'filter', 'include', 'page', 'sort'];

    /**
     * The parameters this library adds beyond the specification.
     */
    public const CUSTOM_QUERY_PARAMS = ['group', 'having'];

    /** @var list<string> */
    private readonly array $knownQueryParams;

    /**
     * @param array<string, mixed> $config
     * @param bool                 $rejectUnknownQueryParams Throw a 400 for a query parameter the
     *                                                       server does not process. JSON:API 1.1
     *                                                       requires this of servers; it is opt-in
     *                                                       because analytics and cache-busting
     *                                                       parameters are commonly tolerated.
     * @param list<string>         $customQueryParams        Parameters to accept besides the
     *                                                       specification's, when rejecting unknown
     *                                                       ones. Defaults to this library's own.
     */
    public function __construct(
        private readonly array $config,
        private readonly bool $rejectUnknownQueryParams = false,
        array $customQueryParams = self::CUSTOM_QUERY_PARAMS,
    ) {
        $this->knownQueryParams = [...self::SPEC_QUERY_PARAMS, ...$customQueryParams];
    }

    public function parse(ServerRequestInterface $request, string $entityClass): JsonApiQueryParams
    {
        if (!isset($this->config[$entityClass])) {
            throw new InvalidArgumentException("Entity class $entityClass not found in configuration");
        }

        $queryParams = $request->getQueryParams();

        if ($this->rejectUnknownQueryParams) {
            $this->rejectUnknown($queryParams);
        }
        $resourceKey = $this->config[$entityClass]['resource_key'];
        $allowedFields = $this->config[$entityClass]['fields'] ?? [];
        $allowedRelationships = $this->config[$entityClass]['relationships'] ?? [];

        // Parse fields — one sparse fieldset per resource type, each narrowed
        // by that type's own allow-list. Types no resource is configured for
        // are ignored; a fieldset that is not a string is a client error.
        $sparseFields = [];
        if (isset($queryParams['fields'])) {
            if (!is_array($queryParams['fields'])) {
                throw new QueryParamMalformed('fields', 'fields must be keyed by resource type, as fields[type]=a,b');
            }
            foreach ($queryParams['fields'] as $type => $list) {
                $typeFields = $this->configFor((string) $type)['fields'] ?? null;
                if ($typeFields === null) {
                    continue;
                }
                $requested = $this->scalarList("fields[$type]", $list);
                $requested = array_values(array_filter(
                    $requested,
                    fn ($field) => in_array($field, $typeFields, true)
                ));
                if ($requested !== []) {
                    $sparseFields[(string) $type] = $requested;
                }
            }
        }
        $fields = $sparseFields[$resourceKey] ?? [];

        // Parse filter with improved validation
        $filter = [];
        if (isset($queryParams['filter']) && is_array($queryParams['filter'])) {
            $filter = $this->parseFilters($queryParams['filter'], $allowedFields);
        }

        // Parse include
        $include = [];
        if (isset($queryParams['include'])) {
            $requestedIncludes = $this->scalarList('include', $queryParams['include']);
            $include = array_values(array_filter(
                $requestedIncludes,
                fn ($rel) => in_array($rel, $allowedRelationships, true)
            ));
        }

        // Parse sort
        $sort = [];
        if (isset($queryParams['sort'])) {
            $sortFields = $this->scalarList('sort', $queryParams['sort']);
            foreach ($sortFields as $field) {
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
            id: $id,
            sparseFields: $sparseFields,
        );
    }

    /**
     * Refuse a query parameter this server does not process.
     *
     * JSON:API 1.1: "If a server encounters a query parameter that does not
     * follow the naming conventions above, or the server does not know how
     * to process it as a query parameter from this specification, it MUST
     * return 400 Bad Request." The naming convention — an implementation's
     * own parameter must contain a character outside `a-z` — exists so that
     * such parameters can never collide with a future specification family;
     * a server that processes none of them has nothing to distinguish and
     * rejects them all.
     *
     * @param array<array-key, mixed> $queryParams
     */
    private function rejectUnknown(array $queryParams): void
    {
        foreach (array_keys($queryParams) as $name) {
            $name = (string) $name;
            if (!in_array($name, $this->knownQueryParams, true)) {
                throw new QueryParamMalformed(
                    $name,
                    "'$name' is not a query parameter this endpoint processes.",
                );
            }
        }
    }

    /**
     * Split a comma-separated query value into trimmed members.
     *
     * `include[]=author` or `fields[posts][]=title` arrives as an array, which
     * `explode()` would reject with a TypeError. That is a malformed request,
     * so it is reported as one — with the parameter named — rather than as a
     * server error.
     *
     * @return list<string>
     */
    private function scalarList(string $param, mixed $value): array
    {
        if (!is_string($value)) {
            throw new QueryParamMalformed($param, "$param must be a comma-separated string");
        }

        return array_map('trim', explode(',', $value));
    }

    /**
     * The configuration of the resource served under a JSON:API type.
     *
     * @return array<string, mixed>|null
     */
    private function configFor(string $resourceKey): ?array
    {
        foreach ($this->config as $entityConfig) {
            if (($entityConfig['resource_key'] ?? null) === $resourceKey) {
                return $entityConfig;
            }
        }

        return null;
    }

    /**
     * Parse and validate filters
     *
     * Handles both simple and complex filter formats:
     * - filter[field]=value
     * - filter[field][operator]=value
     * - filter[field]=value1&filter[field]=value2 (converted to 'in' operator)
     *
     * @param array<array-key, mixed> $filters Raw filter array from query params
     * @param list<string> $allowedFields List of allowed field names
     * @return array<string, mixed> Validated filter array
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
                    $validatedFilters[$key] = ['in' => $value];
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
     * @param array<array-key, mixed> $operators Array of operators and values
     * @return array<string, mixed> Validated operators
     */
    private function validateOperators(array $operators): array
    {
        $validOperators = [];
        $allowedOperators = [
            'eq', 'neq', 'not', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null', 'not_null',
            // DateFilter range operators — kept so filter[field][after]=… survives parsing
            // and reaches DateFilter (which only understands these, not gte/lte).
            'after', 'before', 'strictly_after', 'strictly_before',
            // RangeFilter and ExistsFilter. Without these the filters work when
            // a builder is driven by hand but silently do nothing behind a
            // parsed request, which is the failure that is hardest to notice.
            'between', 'exists',
        ];

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