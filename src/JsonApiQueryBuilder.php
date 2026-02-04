<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use Modufolio\JsonApi\Filter\FilterRegistry;

final class JsonApiQueryBuilder
{
    private QueryBuilder $qb;
    private ExpressionBuilder $expr;
    private ClassMetadata $meta;
    private readonly array $config;
    private array $fields = [];
    private array $filters = [];
    private array $sort = [];
    private array $includes = [];
    private array $params = [];
    private array $page = ['number' => 1, 'size' => 25];
    private ?string $groupBy = null;
    private ?array $having = null;
    private string $operation = 'index';
    public ?string $id = null;
    private array $data = [];
    private bool $debug = false;
    private bool $withTotalCount = false;
    private string $alias = 't0';

    public function __construct(
        array $config,
        private EntityManagerInterface $em,
        private Connection $conn,
        private readonly string $resourceClass,
        private ?FilterRegistry $filterRegistry = null
    ) {
        $this->config = $config;
        $this->qb = $conn->createQueryBuilder();
        $this->expr = $this->qb->expr();
        $this->meta = $em->getClassMetadata($resourceClass);
        $this->qb->from($this->meta->getTableName(), $this->alias);
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // JSON:API Query Parameters
    // ────────────────────────────────────────────────────────────────────────────────

    public function applyParams(JsonApiQueryParams $params): self
    {
        if ($params->fields) {
            $this->fields($params->fields);
        }

        if ($params->filter) {
            $this->filter($params->filter);
        }

        if ($params->include) {
            $this->include($params->include);
        }

        if ($params->sort) {
            $this->sort($params->sort);
        }

        if ($params->page) {
            $this->page($params->page['number'] ?? 1, $params->page['size'] ?? 25);
        }

        if ($params->group) {
            foreach ($params->group as $field) {
                $this->group($field);
            }
        }

        if ($params->having['query'] ?? null) {
            $this->having($params->having['query'], $params->having['bindings'] ?? []);
        }

        if ($params->id) {
            $this->withId($params->id);
        }

        return $this;
    }

    public function fields(array $fields): self
    {
        // Handle sparse fieldsets format: ['resourceType' => ['field1', 'field2']]
        // or simple format: ['field1', 'field2']
        $fieldsToValidate = $fields;

        // Check if this is sparse fieldsets format (nested array with resource types as keys)
        if (!empty($fields) && is_array(reset($fields))) {
            // Extract fields for the current resource type
            $resourceKey = $this->config[$this->resourceClass]['resource_key'] ?? null;
            if ($resourceKey && isset($fields[$resourceKey])) {
                $fieldsToValidate = $fields[$resourceKey];
                $this->fields = $fields[$resourceKey];
            } else {
                // No fields specified for this resource, use all fields
                $fieldsToValidate = [];
                $this->fields = [];
            }
        } else {
            $this->fields = $fields;
        }

        if (!empty($fieldsToValidate)) {
            $this->validateFields($fieldsToValidate);
        }

        return $this;
    }

    public function filter(array $filters): self
    {
        $this->validateFields(array_keys($filters));
        $this->filters = $filters;
        return $this;
    }

    public function sort(array $sort): self
    {
        // Handle both formats: ['field1', '-field2'] and ['field1' => 'ASC', 'field2' => 'DESC']
        foreach ($sort as $key => $value) {
            if (is_string($key) && in_array(strtoupper($value), ['ASC', 'DESC'])) {
                // Associative array format: ['field' => 'ASC']
                $fieldName = $key;
            } else {
                // Indexed array format: ['field'] or ['-field']
                $fieldName = ltrim($value, '-');
            }
            $this->validateFields([$fieldName]);
        }
        $this->sort = $sort;
        return $this;
    }

    public function include(array $includes): self
    {
        foreach ($includes as $path) {
            $relationship = explode('.', $path)[0];
            $this->validateRelationship($relationship);
        }
        $this->includes = $includes;
        return $this;
    }

    public function page(int $number, int $size): self
    {
        $this->page = ['number' => $number, 'size' => $size];
        return $this;
    }

    public function group(string $field): self
    {
        $this->validateFields([$field]);
        $column = $this->getColumnName($field);
        if ($this->groupBy) {
            $this->groupBy .= ", {$this->alias}.{$column}";
        } else {
            $this->groupBy = "{$this->alias}.{$column}";
        }
        return $this;
    }

    public function having(string $condition, array $bindings = []): self
    {
        $this->having = ['query' => $condition, 'bindings' => $bindings];
        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Operation and Data Management
    // ────────────────────────────────────────────────────────────────────────────────

    public function operation(string $operation): self
    {
        $allowedOperations = $this->config[$this->resourceClass]['operations'] ?? ['index' => true];
        if (!isset($allowedOperations[$operation]) || !$allowedOperations[$operation]) {
            throw new InvalidArgumentException("Operation $operation not supported for {$this->resourceClass}");
        }
        $this->operation = $operation;
        return $this;
    }

    public function withId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function withData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function withTotalCount(): self
    {
        $this->withTotalCount = true;
        return $this;
    }

    public function debug(): self
    {
        $this->debug = true;
        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Aggregation Methods
    // ────────────────────────────────────────────────────────────────────────────────

    public function count(): int
    {
        return (int)$this->aggregate('COUNT');
    }

    public function max(string $column): float
    {
        return (float)$this->aggregate('MAX', $column);
    }

    public function min(string $column): float
    {
        return (float)$this->aggregate('MIN', $column);
    }

    public function sum(string $column): float
    {
        return (float)$this->aggregate('SUM', $column);
    }

    public function avg(string $column): float
    {
        return (float)$this->aggregate('AVG', $column);
    }

    private function aggregate(string $method, string $column = '*'): float|int
    {
        if ($column !== '*') {
            $this->validateFields([$column]);
        }

        $this->buildQuery();
        $qb = clone $this->qb;
        $columnName = $column === '*' ? '*' : $this->getColumnName($column);
        $qb->select("$method($this->alias.$columnName) AS aggregation")
            ->setMaxResults(null)
            ->setFirstResult(0);

        return $qb->executeQuery()->fetchOne() ?? 0;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Execution Methods
    // ────────────────────────────────────────────────────────────────────────────────

    public function get(): array
    {
        $result = match ($this->operation) {
            'index' => $this->executeIndex(),
            'show' => $this->executeShow(),
            'create' => $this->executeCreate(),
            'update' => $this->executeUpdate(),
            'delete' => $this->executeDelete(),
            default => throw new InvalidArgumentException("Unknown operation: $this->operation"),
        };

        $this->reset();
        return $result;
    }

    private function executeIndex(): array
    {
        $this->buildQuery();
        if ($this->debug) {
            $qb = clone $this->qb;
            foreach ($this->params as $key => $value) {
                $qb->setParameter($key, $value);
            }
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }

        foreach ($this->params as $key => $value) {
            $this->qb->setParameter($key, $value);
        }
        $rawData = $this->qb->executeQuery()->fetchAllAssociative();

        // Transform raw database rows into JSON:API format
        $data = array_map(fn($row) => $this->transformRowToJsonApi($row), $rawData);

        if (!$this->withTotalCount) {
            return ['data' => $data];
        }

        $count = $this->fetchTotalCount();
        return [
            'total' => $count,
            'data' => $data,
        ];
    }

    private function executeShow(): array
    {
        if (!$this->id) {
            throw new InvalidArgumentException('ID required for show operation');
        }
        $this->buildQuery();
        $this->qb->andWhere("$this->alias.id = :id")->setParameter('id', $this->id);
        if ($this->debug) {
            $qb = clone $this->qb;
            foreach ($this->params as $key => $value) {
                $qb->setParameter($key, $value);
            }
            $qb->setParameter('id', $this->id);
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }
        foreach ($this->params as $key => $value) {
            $this->qb->setParameter($key, $value);
        }
        $rawData = $this->qb->executeQuery()->fetchAssociative();

        if (!$rawData) {
            return [];
        }

        // Transform raw database row into JSON:API format
        return [$this->transformRowToJsonApi($rawData)];
    }

    private function executeCreate(): array
    {
        $allowedFields = $this->getAllowedFields();
        $data = array_intersect_key($this->data, array_flip($allowedFields));
        $mappedData = $this->mapFieldsToColumns($data);
        $mappedData['created_at'] = date('Y-m-d H:i:s');
        $mappedData['updated_at'] = date('Y-m-d H:i:s');

        if ($this->debug) {
            $qb = $this->conn->createQueryBuilder()->insert($this->meta->getTableName());
            foreach ($mappedData as $column => $value) {
                $qb->setValue($column, ':' . $column);
                $qb->setParameter($column, $value);
            }
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }

        $this->conn->insert($this->meta->getTableName(), $mappedData);
        $id = $this->conn->lastInsertId();
        return $this->operation('show')->withId($id)->get();
    }

    private function executeUpdate(): array
    {
        if (!$this->id) {
            throw new InvalidArgumentException('ID required for update operation');
        }
        $allowedFields = $this->getAllowedFields();
        $data = array_intersect_key($this->data, array_flip($allowedFields));
        $mappedData = $this->mapFieldsToColumns($data);
        $mappedData['updated_at'] = date('Y-m-d H:i:s');

        if ($this->debug) {
            $qb = $this->conn->createQueryBuilder()->update($this->meta->getTableName());
            foreach ($mappedData as $column => $value) {
                $qb->set($column, ':' . $column);
                $qb->setParameter($column, $value);
            }
            $qb->where('id = :id')->setParameter('id', $this->id);
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }

        $this->conn->update($this->meta->getTableName(), $mappedData, ['id' => $this->id]);
        return $this->operation('show')->withId($this->id)->get();
    }

    private function executeDelete(): array
    {
        if (!$this->id) {
            throw new InvalidArgumentException('ID required for delete operation');
        }
        if ($this->debug) {
            $qb = $this->conn->createQueryBuilder()->delete($this->meta->getTableName());
            $qb->where('id = :id')->setParameter('id', $this->id);
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }
        $this->conn->delete($this->meta->getTableName(), ['id' => $this->id]);
        return ['status' => 'deleted', 'id' => $this->id];
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Query Building Methods
    // ────────────────────────────────────────────────────────────────────────────────

    private function buildQuery(): void
    {
        // Create a fresh query builder to avoid alias conflicts
        $this->qb = $this->conn->createQueryBuilder();
        $this->expr = $this->qb->expr();
        $this->qb->from($this->meta->getTableName(), $this->alias);
        $this->params = [];

        $select = $this->buildSelect();
        $this->qb->select(...$select['query']);
        $this->params = array_merge($this->params, $select['bindings']);

        $joins = $this->buildJoins();
        foreach ($joins['query'] as $join) {
            $this->qb->leftJoin($join['alias'], $join['table'], $join['joinAlias'], $join['condition']);
            $this->qb->addSelect(...$join['select']);
        }
        $this->params = array_merge($this->params, $joins['bindings']);

        $filters = $this->buildFilters();
        // FilterRegistry applies filters directly to QB, so no need to call andWhere here
        $this->params = array_merge($this->params, $filters['bindings']);

        $group = $this->buildGroup();
        if ($group['query']) {
            $this->qb->addGroupBy($group['query']);
        }

        $having = $this->buildHaving();
        if ($having['query']) {
            $this->qb->having($having['query']);
            $this->params = array_merge($this->params, $having['bindings']);
        }

        $sort = $this->buildSort();
        foreach ($sort['query'] as $sortPart) {
            $this->qb->addOrderBy(...$sortPart);
        }

        $page = $this->buildPage();
        if ($page['bindings']) {
            $this->qb->setFirstResult($page['bindings']['offset']);
            $this->qb->setMaxResults($page['bindings']['size']);
        }
    }

    private function buildSelect(): array
    {
        $select = [];
        $allowedFields = $this->getAllowedFields();
        $fieldsToSelect = $this->fields ?: $allowedFields;

        foreach ($fieldsToSelect as $field) {
            $column = $this->getColumnName($field);
            // Use column name as alias to maintain snake_case in JSON:API output
            $select[] = "$this->alias.$column AS $column";
        }

        // Also select foreign key columns for relationships (for relationship linkage)
        $relationships = $this->getAllowedRelationships();
        foreach ($relationships as $relationship) {
            if ($this->meta->hasAssociation($relationship)) {
                $mapping = $this->meta->getAssociationMapping($relationship);
                // For ManyToOne and OneToOne (owner side), include the foreign key
                if (($mapping['type'] & ClassMetadata::TO_ONE) && isset($mapping['joinColumns'])) {
                    $fkColumn = $mapping['joinColumns'][0]['name'] ?? $relationship . '_id';
                    $select[] = "$this->alias.$fkColumn AS _rel_{$relationship}_id";
                }
            }
        }

        return [
            'query' => $select,
            'bindings' => [],
        ];
    }

    private function buildFilters(): array
    {
        // If FilterRegistry is available and has filters for this resource, use it
        if ($this->filterRegistry && $this->filterRegistry->hasFilters($this->resourceClass)) {
            $bindings = $this->filterRegistry->applyFilters(
                $this->resourceClass,
                $this->qb,
                $this->filters,
                $this->meta->fieldMappings,
                $this->alias
            );

            return [
                'query' => null,
                'bindings' => $bindings,
            ];
        }

        // Fallback to built-in filter logic
        $bindings = [];

        foreach ($this->filters as $field => $value) {
            $column = $this->getColumnName($field);
            $this->applyFilter($column, $value, $bindings);
        }

        return [
            'query' => null,
            'bindings' => $bindings,
        ];
    }

    private function applyFilter(string $column, mixed $value, array &$bindings): void
    {
        $fullColumn = "$this->alias.$column";

        if (!is_array($value)) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->eq($fullColumn, ':' . $param));
            $bindings[$param] = $value;
            return;
        }

        if (array_key_exists('null', $value)) {
            $this->qb->andWhere($this->expr->isNull($fullColumn));
        } elseif (isset($value['not_null'])) {
            $this->qb->andWhere($this->expr->isNotNull($fullColumn));
        } elseif (isset($value['not']) || isset($value['neq'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->neq($fullColumn, ':' . $param));
            $bindings[$param] = $value['not'] ?? $value['neq'];
        } elseif (isset($value['gt'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->gt($fullColumn, ':' . $param));
            $bindings[$param] = $value['gt'];
        } elseif (isset($value['gte'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->gte($fullColumn, ':' . $param));
            $bindings[$param] = $value['gte'];
        } elseif (isset($value['lt'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->lt($fullColumn, ':' . $param));
            $bindings[$param] = $value['lt'];
        } elseif (isset($value['lte'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->lte($fullColumn, ':' . $param));
            $bindings[$param] = $value['lte'];
        } elseif (isset($value['like'])) {
            $param = $this->newParamName();
            $this->qb->andWhere($this->expr->like($fullColumn, ':' . $param));
            $bindings[$param] = $value['like'];
        } elseif (isset($value['in'])) {
            if (empty($value['in'])) {
                $this->qb->andWhere('1 = 0');
            } else {
                $params = [];
                foreach ($value['in'] as $val) {
                    $param = $this->newParamName();
                    $params[] = ':' . $param;
                    $bindings[$param] = $val;
                }
                $this->qb->andWhere($this->expr->in($fullColumn, $params));
            }
        } else {
            throw new InvalidArgumentException("Unsupported filter operator for column: $column");
        }
    }

    private function buildSort(): array
    {
        $sortParts = [];

        // Handle both formats: ['field1', '-field2'] and ['field1' => 'ASC', 'field2' => 'DESC']
        foreach ($this->sort as $key => $value) {
            if (is_string($key) && in_array(strtoupper($value), ['ASC', 'DESC'])) {
                // Associative array format: ['field' => 'ASC']
                $field = $key;
                $direction = strtoupper($value);
            } else {
                // Indexed array format: ['field'] or ['-field']
                $field = $value;
                $direction = 'ASC';
                if (str_starts_with($field, '-')) {
                    $direction = 'DESC';
                    $field = substr($field, 1);
                }
            }
            $column = $this->getColumnName($field);
            $sortParts[] = ["$this->alias.$column", $direction];
        }

        return [
            'query' => $sortParts,
            'bindings' => [],
        ];
    }

    private function buildJoins(): array
    {
        $joins = [];
        $alias = 't0';
        $aliasCounter = 1;
        $usedAliases = ['t0'];

        foreach ($this->includes as $path) {
            $segments = explode('.', $path);
            $currentAlias = $alias;
            $currentMeta = $this->meta;

            foreach ($segments as $index => $segment) {
                if (!$currentMeta->hasAssociation($segment)) {
                    throw new InvalidArgumentException("Unknown include: $segment in path $path");
                }

                while (in_array('t' . $aliasCounter, $usedAliases)) {
                    $aliasCounter++;
                }
                $joinAlias = 't' . $aliasCounter;
                $usedAliases[] = $joinAlias;

                $mapping = $currentMeta->getAssociationMapping($segment);
                $targetClass = $mapping['targetEntity'];
                $targetMeta = $this->em->getClassMetadata($targetClass);
                $targetTable = $targetMeta->getTableName();
                $allowedFields = $this->config[$targetClass]['fields'] ?? $targetMeta->getFieldNames();
                $field = reset($allowedFields);
                if (!$field) {
                    throw new InvalidArgumentException("No fields defined for target entity $targetClass in path $path");
                }
                $column = $targetMeta->fieldMappings[$field]['columnName'] ?? $field;

                if ($mapping['type'] & ClassMetadata::TO_ONE) {
                    // ManyToOne or OneToOne
                    if (empty($mapping['joinColumns'][0])) {
                        throw new InvalidArgumentException("Missing join column configuration for TO_ONE association $segment in path $path");
                    }
                    $joinColumn = $mapping['joinColumns'][0]['name'] ?? 'id';
                    $referencedColumn = $mapping['joinColumns'][0]['referencedColumnName'] ?? 'id';
                    $condition = "$currentAlias.$joinColumn = $joinAlias.$referencedColumn";
                } elseif (isset($mapping['joinTable'])) {
                    // ManyToMany - has a join table
                    $joinTable = $mapping['joinTable']['name'];
                    if (empty($mapping['joinTable']['joinColumns'][0])) {
                        throw new InvalidArgumentException("Missing join column configuration for MANY_TO_MANY association $segment in path $path");
                    }
                    $joinColumn = $mapping['joinTable']['joinColumns'][0]['name'];
                    $condition = "$currentAlias.id = $joinTable.$joinColumn";
                } else {
                    // OneToMany - no join table, use foreign key on target table
                    $mappedBy = $mapping['mappedBy'] ?? null;
                    if (!$mappedBy) {
                        throw new InvalidArgumentException("OneToMany association must have mappedBy property for $segment in path $path");
                    }
                    // Get the inverse side mapping to find the join column
                    $inverseMeta = $targetMeta->getAssociationMapping($mappedBy);
                    if (empty($inverseMeta['joinColumns'][0])) {
                        // Fallback to convention-based column name
                        $joinColumn = $mappedBy . '_id';
                    } else {
                        $joinColumn = $inverseMeta['joinColumns'][0]['name'];
                    }
                    $condition = "$currentAlias.id = $joinAlias.$joinColumn";
                }

                $joins[] = [
                    'alias' => $currentAlias,
                    'table' => $targetTable,
                    'joinAlias' => $joinAlias,
                    'condition' => $condition,
                    'select' => ["$joinAlias.$column AS {$segment}_$field"],
                ];

                $currentAlias = $joinAlias;
                $currentMeta = $targetMeta;
                $aliasCounter++;
            }
        }

        return [
            'query' => $joins,
            'bindings' => [],
        ];
    }

    private function buildGroup(): array
    {
        if (!$this->groupBy) {
            return ['query' => null, 'bindings' => []];
        }
        return [
            'query' => $this->groupBy,
            'bindings' => [],
        ];
    }

    private function buildHaving(): array
    {
        if (!$this->having) {
            return ['query' => null, 'bindings' => []];
        }
        return [
            'query' => $this->having['query'],
            'bindings' => $this->having['bindings'],
        ];
    }

    private function buildPage(): array
    {
        return [
            'query' => null,
            'bindings' => [
                'offset' => ($this->page['number'] - 1) * $this->page['size'],
                'size' => $this->page['size'],
            ],
        ];
    }

    private function fetchTotalCount(): int
    {
        $countQb = clone $this->qb;
        $countQb->select("COUNT($this->alias.id) AS total");
        // Remove pagination from count query
        $countQb->setMaxResults(null);
        $countQb->setFirstResult(0);
        foreach ($this->params as $key => $value) {
            $countQb->setParameter($key, $value);
        }
        return (int)$countQb->executeQuery()->fetchOne();
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Utility Methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @throws Exception
     */
    public function toSql(): string
    {
        $this->buildQuery();
        return $this->qb->getSQL();
    }

    public function getQueryBuilder(): QueryBuilder
    {
        return $this->qb;
    }

    public function expr(): ExpressionBuilder
    {
        return $this->expr;
    }

    private function transformRowToJsonApi(array $row): array
    {
        $id = null;
        $attributes = [];
        $relationships = [];

        foreach ($row as $key => $value) {
            if ($key === 'id') {
                $id = $value;
            } elseif (str_starts_with($key, '_rel_')) {
                // This is a relationship foreign key
                // Extract relationship name from key like "_rel_organization_id"
                $relName = substr($key, 5, -3); // Remove "_rel_" prefix and "_id" suffix
                if ($value !== null) {
                    $mapping = $this->meta->getAssociationMapping($relName);
                    $targetEntity = $mapping['targetEntity'];
                    $resourceKey = $this->config[$targetEntity]['resource_key'] ?? $relName;
                    $relationships[$relName] = [
                        'data' => [
                            'type' => $resourceKey,
                            'id' => (string)$value,
                        ],
                    ];
                }
            } else {
                $attributes[$key] = $value;
            }
        }

        $result = [
            'id' => $id,
            'attributes' => $attributes,
        ];

        if (!empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        return $result;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Internal Helpers
    // ────────────────────────────────────────────────────────────────────────────────

    private function getAllowedFields(): array
    {
        return $this->config[$this->resourceClass]['fields'] ?? $this->meta->getFieldNames();
    }

    private function getAllowedRelationships(): array
    {
        $relationships = $this->config[$this->resourceClass]['relationships'] ?? array_keys($this->meta->getAssociationNames());

        // Handle both array formats: ['rel1', 'rel2'] and ['rel1' => [...], 'rel2' => [...]]
        if (!empty($relationships) && is_array(reset($relationships))) {
            return array_keys($relationships);
        }

        return $relationships;
    }

    private function getColumnName(string $field): string
    {
        return $this->meta->fieldMappings[$field]['columnName'] ?? $field;
    }

    private function mapFieldsToColumns(array $data): array
    {
        $mappedData = [];
        foreach ($data as $field => $value) {
            $column = $this->getColumnName($field);
            $mappedData[$column] = $value;
        }
        return $mappedData;
    }

    private function validateFields(array $fields): void
    {
        $allowedFields = $this->getAllowedFields();
        $invalidFields = array_diff($fields, $allowedFields);
        if ($invalidFields) {
            throw new InvalidArgumentException('Invalid fields: ' . implode(', ', $invalidFields));
        }
    }

    private function validateRelationship(string $relationship): void
    {
        $allowedRelationships = $this->getAllowedRelationships();
        if (!in_array($relationship, $allowedRelationships)) {
            throw new InvalidArgumentException("Invalid relationship: $relationship");
        }
    }

    private function newParamName(): string
    {
        return 'p' . count($this->params);
    }

    private function reset(): void
    {
        $this->fields = [];
        $this->filters = [];
        $this->sort = [];
        $this->includes = [];
        $this->params = [];
        $this->page = ['number' => 1, 'size' => 25];
        $this->groupBy = null;
        $this->having = null;
        $this->operation = 'index';
        $this->id = null;
        $this->data = [];
        $this->debug = false;
        $this->withTotalCount = false;
        $this->qb = $this->conn->createQueryBuilder();
        $this->qb->from($this->meta->getTableName(), $this->alias);
    }

    public function buildUri(): string
    {
        $resourceKey = $this->config[$this->resourceClass]['resource_key'] ?? strtolower((new \ReflectionClass($this->resourceClass))->getShortName());
        $baseUri = "/$resourceKey";
        if ($this->id && $this->operation === 'show') {
            $baseUri .= "/$this->id";
        }

        $queryParts = [];
        if ($this->fields) {
            $queryParts[] = "fields[$resourceKey]=" . implode(',', $this->fields);
        }
        if ($this->includes) {
            $queryParts[] = 'include=' . implode(',', $this->includes);
        }
        if ($this->filters) {
            foreach ($this->filters as $field => $value) {
                if (is_array($value)) {
                    $operator = key($value);
                    if ($operator === 'null') {
                        $queryParts[] = "filter[$field][null]=";
                    } else {
                        $queryParts[] = "filter[$field][$operator]=" . urlencode((string)$value[$operator]);
                    }
                } else {
                    $queryParts[] = "filter[$field]=" . urlencode((string)$value);
                }
            }
        }
        if ($this->groupBy) {
            $queryParts[] = "group=$this->groupBy";
        }
        if ($this->having && $this->having['query']) {
            $havingQuery = $this->having['query'];
            foreach ($this->having['bindings'] as $key => $value) {
                $havingQuery = str_replace(":$key", urlencode((string)$value), $havingQuery);
            }
            $havingQuery = str_replace(' ', '%20', $havingQuery);
            $queryParts[] = "having=$havingQuery";
        }
        if ($this->sort) {
            $queryParts[] = 'sort=' . implode(',', $this->sort);
        }
        if ($this->page['size'] !== 25 || $this->page['number'] !== 1) {
            $queryParts[] = "page[number]={$this->page['number']}";
            $queryParts[] = "page[size]={$this->page['size']}";
        }

        return $baseUri . ($queryParts ? '?' . implode('&', $queryParts) : '');
    }
}