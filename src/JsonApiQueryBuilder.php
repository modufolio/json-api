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
use Modufolio\JsonApi\Exception\FieldUnrecognized;
use Modufolio\JsonApi\Exception\InclusionUnrecognized;
use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Modufolio\JsonApi\Platform\SqlDialect;
use Modufolio\JsonApi\Filter\FilterRegistry;

final class JsonApiQueryBuilder
{
    private QueryBuilder $qb;
    private ExpressionBuilder $expr;
    /** @var ClassMetadata<object> */
    private ClassMetadata $meta;
    /** @var array<string, mixed> */
    private readonly array $config;
    /** @var list<string> */
    private array $fields = [];
    /**
     * Sparse fieldsets as supplied, keyed by resource type. `$fields` holds
     * only the root resource's entry; the rest is kept here so an included
     * resource can be narrowed too.
     *
     * @var array<string, list<string>>
     */
    private array $sparseFields = [];
    /** @var array<array-key, mixed> */
    private array $filters = [];
    /** @var array<array-key, string> */
    private array $sort = [];
    /** @var list<string> */
    private array $includes = [];
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, int|null> */
    private array $page = ['number' => 1, 'size' => 25];
    private ?string $groupBy = null;
    /** @var array<string, mixed>|null */
    private ?array $having = null;
    private string $operation = 'index';
    public ?string $id = null;
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $debug = false;
    private bool $withTotalCount = false;
    private string $alias = 't0';
    /**
     * Row-level scope, keyed by column name after mapping in scope().
     * Deliberately NOT cleared by reset(): the scope describes which rows the
     * caller may touch at all, so a reused builder must keep it — forgetting a
     * security constraint on reuse would fail open.
     *
     * @var array<string, int|float|string|null|list<int|float|string>>
     */
    private array $scope = [];

    /**
     * Set by withoutScope(): this query is deliberately unscoped.
     *
     * Like $scope itself, NOT cleared by reset() — a waiver is a statement
     * about the caller's intent for this builder, and silently revoking it on
     * reuse would swap a deliberate decision for an accidental one.
     */
    private bool $scopeWaived = false;
    
    // Security: Pattern for validating SQL identifiers
    private const SQL_IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
    
    // Security: Maximum depth for nested filter operations to prevent DoS
    private const MAX_FILTER_DEPTH = 5;

    /**
     * @param array<string, mixed>  $config
     * @param class-string          $resourceClass
     */
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

    /**
     * @param array<array-key, mixed> $fields
     */
    public function fields(array $fields): self
    {
        // Handle sparse fieldsets format: ['resourceType' => ['field1', 'field2']]
        // or simple format: ['field1', 'field2']
        $fieldsToValidate = $fields;

        // Check if this is sparse fieldsets format (nested array with resource types as keys)
        if (!empty($fields) && is_array(reset($fields))) {
            // Retain the whole map: buildJoins() and fetchToManyIncludes()
            // narrow included resources with it.
            $this->sparseFields = $fields;
            // Extract fields for the current resource type
            $resourceKey = $this->config[$this->resourceClass]['resource_key'] ?? null;
            if ($resourceKey && isset($fields[$resourceKey])) {
                $fieldsToValidate = $fields[$resourceKey];
                /** @var list<string> $selected */
                $selected = array_values($fields[$resourceKey]);
                $this->fields = $selected;
            } else {
                // No fields specified for this resource, use all fields
                $fieldsToValidate = [];
                $this->fields = [];
            }
        } else {
            /** @var list<string> $selected */
            $selected = array_values($fields);
            $this->fields = $selected;
        }

        if (!empty($fieldsToValidate)) {
            $this->validateFields($fieldsToValidate);
        }

        return $this;
    }

    /**
     * @param array<array-key, mixed> $filters
     */
    public function filter(array $filters): self
    {
        $this->validateFields(array_keys($filters));
        $this->filters = $filters;
        return $this;
    }

    /**
     * @param array<array-key, string> $sort
     */
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

    /**
     * @param list<string> $includes
     */
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

    /**
     * Return every matching row, with no LIMIT.
     *
     * Pagination is otherwise unconditional — the default page size applies
     * even when the caller never asked for it — so a caller that legitimately
     * needs the whole set (an export, a board that renders all its cards) had
     * no way to say so and silently received the first page instead.
     *
     * Use deliberately: the result set is then bounded only by the data.
     */
    public function withoutPagination(): self
    {
        $this->page = ['number' => 1, 'size' => null];
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

    /**
     * @param array<string, mixed> $bindings
     */
    public function having(string $condition, array $bindings = []): self
    {
        // Security: Validate that condition doesn't contain dangerous SQL
        if (!$this->isValidHavingCondition($condition)) {
            throw new InvalidArgumentException('Invalid HAVING condition - only aggregations and simple comparisons allowed');
        }
        
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

    /**
     * @param array<string, mixed> $data
     */
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

    /**
     * Restrict every operation to the rows matching these constraints.
     *
     * This is the row-level counterpart to the config's roles: "which rows may
     * this caller touch" (the current tenant's, the current user's own), stated
     * once and enforced uniformly — index and show add the constraints to their
     * WHERE clause, update and delete refuse to touch rows outside it (an
     * out-of-scope id behaves exactly like a missing one, so it cannot be told
     * apart), and create forces the scoped values onto the new row. Enforcing a
     * scope on reads but not writes is how records leak across tenants; a
     * single declaration point removes that asymmetry.
     *
     * Keys are field names or to-one relationship names of the resource; values
     * are a scalar (equality), null (IS NULL), or a non-empty list of scalars
     * (IN). Values must come from trusted context (the authenticated user, the
     * resolved tenant) — never from request input. Repeated calls merge, later
     * values winning per key.
     *
     * @param array<string, int|float|string|bool|null|list<int|float|string|bool>> $scope
     */
    public function scope(array $scope): self
    {
        foreach ($scope as $field => $value) {
            $column = $this->resolveScopeColumn($field);

            if (is_array($value)) {
                if ($value === []) {
                    // An empty IN () matches nothing; a scope that can never
                    // match is almost certainly a bug upstream (an unresolved
                    // tenant), and silently returning nothing would mask it.
                    throw new InvalidArgumentException("Scope for '$field' is an empty list; refusing a scope that can never match.");
                }
                $value = array_map($this->normalizeScopeScalar(...), $value);
            } elseif ($value !== null) {
                $value = $this->normalizeScopeScalar($value);
            }

            $this->scope[$column] = $value;
        }

        return $this;
    }

    /**
     * Run this query without a scope, on purpose.
     *
     * Only meaningful for an entity declared scoped via
     * `JsonApiConfigurator::scopeBy()`, whose builder otherwise refuses to
     * execute unscoped. The point is that the escape is written down: an
     * admin-wide report or a console command says so at the call site, and a
     * reviewer can see the difference between "global by intent" and "nobody
     * remembered".
     */
    public function withoutScope(): self
    {
        $this->scopeWaived = true;

        return $this;
    }

    /**
     * Refuse to execute when the resource is declared scoped and this builder
     * has no value for one of the scoped fields.
     *
     * Checked at execution rather than when scope() is called, because the
     * order of the fluent calls is the caller's business — what matters is the
     * state at the moment a query would run.
     *
     * @throws InvalidArgumentException
     */
    private function assertScopeSatisfied(): void
    {
        if ($this->scopeWaived) {
            return;
        }

        /** @var list<string> $required */
        $required = $this->config[$this->resourceClass]['scope_by'] ?? [];

        $missing = [];

        foreach ($required as $field) {
            // Resolved the same way scope() resolves it, so a declaration
            // naming an association matches a scope set on that association.
            $column = $this->resolveScopeColumn($field);

            if (!array_key_exists($column, $this->scope)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s is declared scoped by "%s"; no scope was set for %s. '
                . 'Call scope([...]) with the caller\'s partition, or withoutScope() if this query is global on purpose.',
                $this->resourceClass,
                implode('", "', $required),
                '"' . implode('", "', $missing) . '"',
            ));
        }
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
        // Aggregates leak just as much as rows: an unscoped COUNT tells you how
        // many records the other tenants have.
        $this->assertScopeSatisfied();

        if ($column !== '*') {
            $this->validateFields([$column]);
        }

        $this->buildQuery();
        $qb = clone $this->qb;
        $expr = $column === '*' ? "$method(*)" : "$method($this->alias.{$this->conn->quoteIdentifier($this->getColumnName($column))})";
        $qb->select("$expr AS aggregation")
            ->setMaxResults(null)
            ->setFirstResult(0);

        foreach ($this->params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        $value = $qb->executeQuery()->fetchOne();

        // PostgreSQL types AVG() and SUM() as `numeric`, which PDO hands back
        // as a string rather than a number — the other engines return an int
        // or a float. `+ 0` normalises without deciding which of the two it
        // should be: a COUNT stays an int, an AVG becomes a float.
        if ($value === null || $value === false || !is_numeric($value)) {
            return 0;
        }

        return $value + 0;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Execution Methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $this->assertScopeSatisfied();
        $this->resolveIdentifier();

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

    /**
     * @return array<string, mixed>
     */
    private function executeIndex(): array
    {
        $this->guardAgainstGrouping();
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

        // Resolve OneToMany linkage (and included data for requested rels) via separate IN-queries.
        $parentIds  = array_column($rawData, 'id');
        $toManyData = $this->fetchToManyIncludes($parentIds);
        $linkageMap  = $toManyData['linkage'];   // all OneToMany → always add to relationships
        $includedRaw = $toManyData['included'];  // only ?include= rels → add to compound document
        $included = [];
        if (!empty($linkageMap)) {
            $includedMap = [];
            foreach ($data as &$item) {
                $itemId = $item['id'];
                foreach ($linkageMap as $relName => $byParent) {
                    $item['relationships'][$relName] = ['data' => $byParent[$itemId] ?? []];
                }
            }
            unset($item);
            foreach ($includedRaw as $byParent) {
                foreach ($byParent as $items) {
                    foreach ($items as $i) {
                        $includedMap[$i['type'] . ':' . $i['id']] = $i;
                    }
                }
            }
            $included = array_values($includedMap);
        }

        if (!$this->withTotalCount) {
            return ['data' => $data, 'included' => $included];
        }

        $count = $this->fetchTotalCount();
        return [
            'total' => $count,
            'data' => $data,
            'included' => $included,
        ];
    }

    /**
     * Grouping cannot produce resources, so it is refused where resources are
     * what the operation returns.
     *
     * A grouped query selects one row per group, and that row has no `id` —
     * JSON:API requires one on every resource object, so the document could
     * not be valid whatever the engine did. What the builder emitted instead
     * was `SELECT <every field> … GROUP BY <one field>`, which is invalid SQL:
     * PostgreSQL and MySQL (with its default ONLY_FULL_GROUP_BY) both reject
     * it, and only SQLite's leniency made the feature appear to work.
     *
     * `group()` and `having()` remain available behind the aggregate helpers —
     * count(), sum(), avg(), min(), max() — which replace the SELECT with the
     * aggregate and so group legitimately.
     */
    private function guardAgainstGrouping(): void
    {
        if ($this->groupBy === null && $this->having === null) {
            return;
        }

        throw new QueryParamMalformed(
            $this->groupBy !== null ? 'group' : 'having',
            'Grouping cannot be combined with an operation that returns resources: '
            . 'a grouped row has no id. Use count(), sum(), avg(), min() or max() '
            . 'to read an aggregate over a group.',
        );
    }

    /**
     * A non-numeric id is treated as a uuid when the entity maps a 'uuid'
     * field, and swapped for the numeric primary key so every downstream
     * id comparison stays unchanged. Unresolvable ids become '0', which no
     * row matches — the operation then not-founds through its normal path.
     */
    private function resolveIdentifier(): void
    {
        if ($this->id === null || ctype_digit($this->id)) {
            return;
        }

        if (!$this->meta->hasField('uuid')) {
            $this->id = '-1';
            return;
        }

        $resolved = $this->conn->createQueryBuilder()
            ->select($this->conn->quoteIdentifier('id'))
            ->from($this->meta->getTableName())
            ->where($this->conn->quoteIdentifier($this->meta->getColumnName('uuid')) . ' = :uuid')
            ->setParameter('uuid', $this->id)
            ->executeQuery()
            ->fetchOne();

        $this->id = $resolved === false ? '-1' : (string) $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function executeShow(): array
    {
        $this->guardAgainstGrouping();

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
            // No such record. The caller turns a null `data` into a 404 — the
            // spec has no document shape for a missing single resource, and a
            // bare [] could not be told apart from a malformed result.
            return ['data' => null];
        }

        $item = $this->transformRowToJsonApi($rawData);

        // Resolve OneToMany linkage (and included data for requested rels) via separate IN-queries.
        $toManyData  = $this->fetchToManyIncludes([$this->id]);
        $linkageMap  = $toManyData['linkage'];   // all OneToMany → always add to relationships
        $includedRaw = $toManyData['included'];  // only ?include= rels → add to compound document

        foreach ($linkageMap as $relName => $byParent) {
            $item['relationships'][$relName] = ['data' => $byParent[$this->id] ?? []];
        }

        // Keyed by type:id while collecting: a compound document must not carry
        // two resource objects for the same pair, and two included
        // relationships can legitimately resolve to the same record.
        $includedMap = [];
        foreach ($includedRaw as $byParent) {
            foreach ($byParent as $items) {
                foreach ($items as $i) {
                    $includedMap[$i['type'] . ':' . $i['id']] = $i;
                }
            }
        }
        $included = array_values($includedMap);

        // A single resource is the `data` member itself, not a one-element
        // list — the same split every JSON:API implementation makes between a
        // resource document and a collection document.
        return ['data' => $item, 'included' => $included];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCreate(): array
    {
        $allowedFields = $this->getAllowedFields();
        $data = array_intersect_key($this->data, array_flip($allowedFields));
        $mappedData = $this->mapFieldsToColumns($data);
        $columns = array_column($this->meta->fieldMappings, 'columnName');
        if (in_array('created_at', $columns, true)) {
            $mappedData['created_at'] = date('Y-m-d H:i:s');
        }
        if (in_array('updated_at', $columns, true)) {
            $mappedData['updated_at'] = date('Y-m-d H:i:s');
        }

        // A scoped create must produce a row inside the scope — otherwise a
        // caller could create records they can neither see nor touch again
        // (or worse, park them in another tenant).
        $mappedData = $this->applyScopeToCreateData($mappedData);

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
        return $this->operation('show')->withId((string) $id)->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function executeUpdate(): array
    {
        if (!$this->id) {
            throw new InvalidArgumentException('ID required for update operation');
        }
        $allowedFields = $this->getAllowedFields();
        $data = array_intersect_key($this->data, array_flip($allowedFields));
        $mappedData = $this->mapFieldsToColumns($data);
        $columns = array_column($this->meta->fieldMappings, 'columnName');
        if (in_array('updated_at', $columns, true)) {
            $mappedData['updated_at'] = date('Y-m-d H:i:s');
        }

        $qb = $this->conn->createQueryBuilder()->update($this->meta->getTableName());
        foreach ($mappedData as $column => $value) {
            $qb->set($column, ':' . $column);
            $qb->setParameter($column, $value);
        }
        $qb->where('id = :id')->setParameter('id', $this->id);

        // The scope guards writes exactly like reads: a row outside it is left
        // untouched, and the scoped re-read below then reports it exactly like
        // a missing one (`data: null`), so an out-of-scope id cannot be told
        // apart from a nonexistent one.
        $scope = $this->scopeConditions(null);
        foreach ($scope['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($scope['bindings'] as $key => $value) {
            $qb->setParameter($key, $value);
        }

        if ($this->debug) {
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }

        $qb->executeStatement();
        return $this->operation('show')->withId($this->id)->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function executeDelete(): array
    {
        if (!$this->id) {
            throw new InvalidArgumentException('ID required for delete operation');
        }

        $qb = $this->conn->createQueryBuilder()->delete($this->meta->getTableName());
        $qb->where('id = :id')->setParameter('id', $this->id);

        // Same containment as update: a row outside the scope is not deleted.
        $scope = $this->scopeConditions(null);
        foreach ($scope['conditions'] as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($scope['bindings'] as $key => $value) {
            $qb->setParameter($key, $value);
        }

        if ($this->debug) {
            return [
                'query' => $qb->getSQL(),
                'bindings' => $qb->getParameters(),
            ];
        }

        $affected = $qb->executeStatement();

        if ($affected === 0) {
            // Nothing matched — the id does not exist, or the scope excludes
            // it; the two are deliberately indistinguishable. Same convention
            // as show: the caller turns a null `data` into a 404.
            return ['data' => null];
        }

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

        // Row-level scope (see scope()). Applied after client filters so a
        // crafted filter can only narrow the scoped set, never widen it.
        $scope = $this->scopeConditions($this->alias);
        foreach ($scope['conditions'] as $condition) {
            $this->qb->andWhere($condition);
        }
        $this->params = array_merge($this->params, $scope['bindings']);

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
        $dialect = SqlDialect::for($this->conn->getDatabasePlatform());
        foreach ($sort['query'] as [$expression, $direction]) {
            // Each engine has its own idea of where NULLs belong, so the same
            // sort returns a different first page on each. The dialect pins
            // them to the end everywhere.
            foreach ($dialect->orderByNullsLast($expression, $direction) as $orderBy) {
                $this->qb->addOrderBy($orderBy);
            }
        }

        $page = $this->buildPage();
        if ($page['bindings']) {
            $this->qb->setFirstResult($page['bindings']['offset']);
            $this->qb->setMaxResults($page['bindings']['size']);
        }
    }

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
    private function buildSelect(): array
    {
        $select = [];
        $allowedFields = $this->getAllowedFields();
        $fieldsToSelect = $this->fields ?: $allowedFields;

        foreach ($fieldsToSelect as $field) {
            $column = $this->getColumnName($field);
            $quotedColumn = $this->conn->quoteIdentifier($column);
            $select[] = "$this->alias.$quotedColumn AS $column";
        }

        // Also select foreign key columns for relationships (for relationship linkage)
        $relationships = $this->getAllowedRelationships();
        foreach ($relationships as $relationship) {
            if ($this->meta->hasAssociation($relationship)) {
                $mapping = $this->meta->getAssociationMapping($relationship);
                // For ManyToOne and OneToOne (owner side), include the foreign key
                if (($mapping['type'] & ClassMetadata::TO_ONE) && isset($mapping['joinColumns'])) {
                    $fkColumn = $mapping['joinColumns'][0]['name'] ?? $relationship . '_id';
                    $quotedFkColumn = $this->conn->quoteIdentifier($fkColumn);
                    $select[] = "$this->alias.$quotedFkColumn AS _rel_{$relationship}_id";
                }
            }
        }

        return [
            'query' => $select,
            'bindings' => [],
        ];
    }

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
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

    /**
     * @param array<string, mixed> $bindings
     */
    private function applyFilter(string $column, mixed $value, array &$bindings): void
    {
        // Security: Validate column name before using it
        if (!$this->isValidColumnName($column)) {
            throw new InvalidArgumentException("Invalid column name: $column");
        }
        
        // Security: Prevent deeply nested filter structures (DoS protection)
        if ($this->getFilterDepth($value) > self::MAX_FILTER_DEPTH) {
            throw new InvalidArgumentException('Filter structure too deep - maximum depth exceeded');
        }
        
        $fullColumn = "$this->alias.$column";
        $safeExpr = $this->getSafeExpressionBuilder();

        if (!is_array($value)) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->eq($fullColumn, ':' . $param));
            $bindings[$param] = $value;
            return;
        }

        if (array_key_exists('null', $value)) {
            $this->qb->andWhere($safeExpr->isNull($fullColumn));
        } elseif (isset($value['not_null'])) {
            $this->qb->andWhere($safeExpr->isNotNull($fullColumn));
        } elseif (isset($value['not']) || isset($value['neq'])) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->neq($fullColumn, ':' . $param));
            $bindings[$param] = $value['not'] ?? $value['neq'];
        } elseif (isset($value['gt'])) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->gt($fullColumn, ':' . $param));
            $bindings[$param] = $value['gt'];
        } elseif (isset($value['gte'])) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->gte($fullColumn, ':' . $param));
            $bindings[$param] = $value['gte'];
        } elseif (isset($value['lt'])) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->lt($fullColumn, ':' . $param));
            $bindings[$param] = $value['lt'];
        } elseif (isset($value['lte'])) {
            $param = $this->newParamName($bindings);
            $this->qb->andWhere($safeExpr->lte($fullColumn, ':' . $param));
            $bindings[$param] = $value['lte'];
        } elseif (isset($value['like'])) {
            $param = $this->newParamName($bindings);
            $sanitizedValue = $this->sanitizeLikePattern($value['like']);
            $this->qb->andWhere($safeExpr->like($fullColumn, ':' . $param));
            $bindings[$param] = $sanitizedValue;
        } elseif (isset($value['in'])) {
            if (empty($value['in'])) {
                $this->qb->andWhere('1 = 0');
            } else {
                if (count($value['in']) > 1000) {
                    throw new InvalidArgumentException('IN clause contains too many values - maximum 1000 allowed');
                }

                $params = [];
                foreach ($value['in'] as $val) {
                    $param = $this->newParamName($bindings);
                    $params[] = ':' . $param;
                    $bindings[$param] = $val;
                }
                $this->qb->andWhere($safeExpr->in($fullColumn, $params));
            }
        } else {
            throw new InvalidArgumentException("Unsupported filter operator for column: $column");
        }
    }

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
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

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
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
                    throw new InclusionUnrecognized($path, "Unknown include: $segment in path $path");
                }

                $mapping = $currentMeta->getAssociationMapping($segment);

                // To-many — skip LEFT JOIN to prevent row multiplication, which
                // would also make LIMIT slice joined rows rather than records.
                // Both OneToMany and ManyToMany are resolved by
                // fetchToManyIncludes() after the main query instead.
                if (!($mapping['type'] & ClassMetadata::TO_ONE)) {
                    break;
                }

                while (in_array('t' . $aliasCounter, $usedAliases)) {
                    $aliasCounter++;
                }
                $joinAlias = 't' . $aliasCounter;
                $usedAliases[] = $joinAlias;

                $targetClass = $mapping['targetEntity'];
                $targetMeta = $this->em->getClassMetadata($targetClass);
                $targetTable = $targetMeta->getTableName();
                $allowedFields = $this->targetFields($targetClass, $targetMeta);

                if ($allowedFields === []) {
                    throw new InvalidArgumentException("No fields defined for target entity $targetClass in path $path");
                }

                // Every allowed field, not just the first: a caller needing two
                // columns off a joined record (a person's first and last name)
                // otherwise had no way to ask for the second.
                $select = [];

                foreach ($allowedFields as $field) {
                    $column = $targetMeta->fieldMappings[$field]['columnName'] ?? $field;
                    $select[] = "$joinAlias.$column AS {$segment}_$field";
                }

                // ManyToOne or OneToOne — to-many never reaches here.
                $joinColumn = $mapping['joinColumns'][0]['name'] ?? 'id';
                $referencedColumn = $mapping['joinColumns'][0]['referencedColumnName'] ?? 'id';
                $condition = "$currentAlias.$joinColumn = $joinAlias.$referencedColumn";

                $joins[] = [
                    'alias' => $currentAlias,
                    'table' => $targetTable,
                    'joinAlias' => $joinAlias,
                    'condition' => $condition,
                    'select' => $select,
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
     * it by key, as the rest of this class does.
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

        $targetMeta = $this->em->getClassMetadata($mapping['targetEntity']);

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

    /**
     * The fields to read from an included resource: its configured allow-list,
     * narrowed by a sparse fieldset for that type when one was supplied.
     *
     * @param ClassMetadata<object> $targetMeta
     *
     * @return array<int, string>
     */
    private function targetFields(string $targetClass, ClassMetadata $targetMeta): array
    {
        $allowed = $this->config[$targetClass]['fields'] ?? $targetMeta->getFieldNames();
        $resourceKey = $this->config[$targetClass]['resource_key'] ?? null;

        if ($resourceKey !== null && isset($this->sparseFields[$resourceKey])) {
            $requested = array_intersect($allowed, (array) $this->sparseFields[$resourceKey]);

            // An empty intersection means the caller asked only for fields this
            // resource does not expose; the allow-list wins over the request.
            if ($requested !== []) {
                return array_values($requested);
            }
        }

        return array_values($allowed);
    }

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
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

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
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

    /**
     * @return array{query: mixed, bindings: array<string, mixed>}
     */
    private function buildPage(): array
    {
        // A null size means pagination was explicitly switched off; returning
        // no bindings is what tells buildQuery() to leave LIMIT/OFFSET unset.
        if ($this->page['size'] === null) {
            return ['query' => null, 'bindings' => []];
        }

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
        // DISTINCT because the built query may carry joins: a to-one join is
        // one-to-one, but a self-referencing or nullable chain — and any join
        // a caller added themselves — can repeat a root row and inflate the
        // total the pager is built from.
        $countQb->select("COUNT(DISTINCT $this->alias.id) AS total");
        $countQb->resetGroupBy();
        // An ORDER BY on a column the aggregate does not group by is invalid
        // SQL — PostgreSQL rejects it outright, and it is wasted sorting on the
        // engines that tolerate it, since a count has one row to order.
        $countQb->resetOrderBy();
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

    /**
     * Fetch OneToMany related resources via separate IN-queries, grouped by parent ID.
     *
     * Always fetches ALL configured OneToMany relationships so that relationship linkage
     * (type+id pairs) appears in every response regardless of ?include.
     *
     * Returns:
     *   [
     *     'linkage'  => [ relName => [ parentId => [ ['type'=>..,'id'=>..], … ] ] ],
     *     'included' => [ relName => [ parentId => [ fullItem, … ] ] ],  // only for ?include=rel
     *   ]
     */
    /**
     * @param array<array-key, int|string|null> $parentIds
     *
     * @return array<string, mixed>
     */
    private function fetchToManyIncludes(array $parentIds): array
    {
        if (empty($parentIds)) {
            return ['linkage' => [], 'included' => []];
        }

        // Relationships explicitly requested via ?include=
        $requestedIncludes = [];
        /** @var array<string, list<string>> $nestedIncludes */
        $nestedIncludes = [];
        foreach ($this->includes as $path) {
            $segments = explode('.', $path);
            $requestedIncludes[$segments[0]] = true;

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

                $nestedIncludes[$segments[0]][] = $segments[1];
            }
        }

        // All OneToMany associations configured for this resource
        $allRelationships = $this->getAllowedRelationships();

        $linkage  = [];
        $included = [];

        foreach ($allRelationships as $segment) {
            if (!$this->meta->hasAssociation($segment)) {
                continue;
            }

            $mapping = $this->meta->getAssociationMapping($segment);

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

            $targetClass  = $mapping['targetEntity'];
            $targetMeta   = $this->em->getClassMetadata($targetClass);
            $targetTable  = $targetMeta->getTableName();
            $targetKey    = $this->config[$targetClass]['resource_key']
                ?? strtolower(substr($targetClass, strrpos($targetClass, '\\') + 1));

            if ($manyToMany === null) {
                $inverseMapping = $targetMeta->getAssociationMapping($mappedBy);
                $fkColumn       = $inverseMapping['joinColumns'][0]['name'] ?? $mappedBy . '_id';
            } else {
                $fkColumn = $manyToMany['parentColumn'];
            }

            // Always need id; only fetch full fields when this rel is in ?include
            $isIncluded = isset($requestedIncludes[$segment]);

            $selectParts = [$this->conn->quoteIdentifier('id')];

            if ($isIncluded) {
                $allowedFields = $this->targetFields($targetClass, $targetMeta);
                foreach ($allowedFields as $field) {
                    $col = $targetMeta->fieldMappings[$field]['columnName'] ?? $field;
                    if ($col !== 'id') {
                        $selectParts[] = $this->conn->quoteIdentifier($col);
                    }
                }

                // Add FK columns for TO_ONE relationships on the target entity
                $targetRels = $this->config[$targetClass]['relationships'] ?? array_keys($targetMeta->getAssociationNames());
                foreach ($targetRels as $rel) {
                    if (!$targetMeta->hasAssociation($rel)) {
                        continue;
                    }
                    $relMapping = $targetMeta->getAssociationMapping($rel);
                    if (($relMapping['type'] & ClassMetadata::TO_ONE) && isset($relMapping['joinColumns'])) {
                        $relFk         = $relMapping['joinColumns'][0]['name'] ?? $rel . '_id';
                        $selectParts[] = $this->conn->quoteIdentifier($relFk) . ' AS _rel_' . $rel . '_id';
                    }
                }
            }

            // Nested `rel.subRel` — join the to-one named by the second segment
            // and select its columns onto the included row, so a caller can
            // read a related record's fields rather than only its foreign key.
            $nestedJoins = [];
            $nestedSelects = [];
            $nestedAlias = 0;

            if ($isIncluded) {
                foreach ($nestedIncludes[$segment] ?? [] as $subSegment) {
                    if (!$targetMeta->hasAssociation($subSegment)) {
                        throw new InclusionUnrecognized(
                            "$segment.$subSegment",
                            "Unknown include: $subSegment in path $segment.$subSegment"
                        );
                    }

                    $subMapping = $targetMeta->getAssociationMapping($subSegment);

                    // Only to-one nests here. A to-many under a to-many would
                    // need its own batched query per parent set; rejecting it
                    // is better than silently returning nothing.
                    if (!($subMapping['type'] & ClassMetadata::TO_ONE) || !isset($subMapping['joinColumns'])) {
                        throw new InclusionUnrecognized(
                            "$segment.$subSegment",
                            "Nested include $segment.$subSegment is not a to-one relationship; "
                            . 'only to-one nesting is supported.'
                        );
                    }

                    $subClass = $subMapping['targetEntity'];
                    $subMeta = $this->em->getClassMetadata($subClass);
                    $subAlias = 'n' . $nestedAlias++;
                    $subFk = $subMapping['joinColumns'][0]['name'] ?? $subSegment . '_id';
                    $subReferenced = $subMapping['joinColumns'][0]['referencedColumnName'] ?? 'id';

                    $nestedJoins[] = sprintf(
                        'LEFT JOIN %s %s ON %%s.%s = %s.%s',
                        $this->conn->quoteIdentifier($subMeta->getTableName()),
                        $subAlias,
                        $this->conn->quoteIdentifier($subFk),
                        $subAlias,
                        $this->conn->quoteIdentifier($subReferenced)
                    );

                    foreach ($this->targetFields($subClass, $subMeta) as $subField) {
                        $subColumn = $subMeta->fieldMappings[$subField]['columnName'] ?? $subField;
                        $nestedSelects[] = sprintf(
                            '%s.%s AS %s_%s',
                            $subAlias,
                            $this->conn->quoteIdentifier($subColumn),
                            $subSegment,
                            $subField
                        );
                    }
                }
            }

            // Build IN clause with named parameters
            $placeholders = [];
            $bindings     = [];
            foreach ($parentIds as $i => $pid) {
                $pname          = 'tm_' . $i;
                $placeholders[] = ':' . $pname;
                $bindings[$pname] = $pid;
            }

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

            if ($manyToMany === null) {
                // OneToMany: the foreign key lives on the target row itself.
                $sql = sprintf(
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
            } else {
                // ManyToMany: the owning row is reached through the join table,
                // which supplies the parent id.
                $joinTable = $this->conn->quoteIdentifier($manyToMany['joinTable']);
                $quotedTargetColumn = $this->conn->quoteIdentifier($manyToMany['targetColumn']);

                $sql = sprintf(
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

            $rows = $this->conn->executeQuery($sql, $bindings)->fetchAllAssociative();
            foreach ($rows as $row) {
                $parentId = $row['__parent_id'];
                unset($row['__parent_id']);
                $identifier = ['type' => $targetKey, 'id' => (string) $row['id']];
                $linkage[$segment][$parentId][] = $identifier;
                if ($isIncluded) {
                    $included[$segment][$parentId][] = $this->transformIncludedRowToJsonApi($row, $targetMeta, $targetKey);
                }
            }
        }

        return ['linkage' => $linkage, 'included' => $included];
    }

    /**
     * Like transformRowToJsonApi() but for a related (included) entity,
     * using the target entity's ClassMetadata for relationship resolution.
     * Returns an item with 'type' included so the caller can build JSON:API identifiers.
     *
     * @param array<string, mixed>  $row
     * @param ClassMetadata<object> $targetMeta
     *
     * @return array<string, mixed>
     */
    private function transformIncludedRowToJsonApi(array $row, ClassMetadata $targetMeta, string $resourceKey): array
    {
        $id            = null;
        $attributes    = [];
        $relationships = [];

        foreach ($row as $key => $value) {
            if ($key === 'id') {
                $id = $value;
            } elseif (str_starts_with($key, '_rel_')) {
                // e.g. _rel_connectedContact_id  →  relName = connectedContact
                $relName = substr($key, 5, -3);
                if ($value !== null) {
                    try {
                        $relMapping      = $targetMeta->getAssociationMapping($relName);
                        $relTargetClass  = $relMapping['targetEntity'];
                        $relResourceKey  = $this->config[$relTargetClass]['resource_key'] ?? $relName;
                        $relationships[$relName] = [
                            'data' => ['type' => $relResourceKey, 'id' => (string) $value],
                        ];
                    } catch (\Exception $e) {
                        // skip unmapped / inaccessible relationships
                    }
                }
            } else {
                $attributes[$key] = $value;
            }
        }

        $result = [
            'type'       => $resourceKey,
            'id'         => (string) $id,
            'attributes' => $attributes,
        ];

        if (!empty($relationships)) {
            $result['relationships'] = $relationships;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
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

    /**
     * @return list<string>
     */
    private function getAllowedFields(): array
    {
        return $this->config[$this->resourceClass]['fields'] ?? $this->meta->getFieldNames();
    }

    /**
     * @return list<string>
     */
    private function getAllowedRelationships(): array
    {
        $relationships = $this->config[$this->resourceClass]['relationships'] ?? array_keys($this->meta->getAssociationNames());

        // Handle both array formats: ['rel1', 'rel2'] and ['rel1' => [...], 'rel2' => [...]]
        if (!empty($relationships) && is_array(reset($relationships))) {
            $relationships = array_keys($relationships);
        }

        // A numeric-looking relationship name arrives as an int key; the
        // metadata lookups downstream take a string.
        return array_map(strval(...), array_values($relationships));
    }

    private function getColumnName(string $field): string
    {
        return $this->meta->fieldMappings[$field]['columnName'] ?? $field;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mapFieldsToColumns(array $data): array
    {
        $mappedData = [];
        foreach ($data as $field => $value) {
            $column = $this->getColumnName($field);
            $mappedData[$column] = $value;
        }
        return $mappedData;
    }

    /**
     * Map a scope key (field name or to-one relationship name) to its column.
     *
     * Unknown keys throw instead of passing through: a misspelled scope key
     * that silently matched nothing — or worse, everything — would defeat the
     * constraint it was meant to enforce.
     */
    private function resolveScopeColumn(string $field): string
    {
        if (isset($this->meta->fieldMappings[$field])) {
            $column = $this->meta->fieldMappings[$field]['columnName'];
        } elseif ($this->meta->hasAssociation($field)) {
            $mapping = $this->meta->getAssociationMapping($field);
            if (!($mapping['type'] & ClassMetadata::TO_ONE) || !isset($mapping['joinColumns'])) {
                throw new InvalidArgumentException("Scope key '$field' is a to-many relationship; scopes constrain columns of {$this->resourceClass} itself.");
            }
            $column = $mapping['joinColumns'][0]['name'] ?? $field . '_id';
        } else {
            throw new InvalidArgumentException("Unknown scope key '$field' for {$this->resourceClass}; expected a field or to-one relationship name.");
        }

        if (!preg_match(self::SQL_IDENTIFIER_PATTERN, $column)) {
            throw new InvalidArgumentException("Scope column '$column' is not a valid SQL identifier.");
        }

        return $column;
    }

    /**
     * @param mixed $value
     */
    private function normalizeScopeScalar(mixed $value): int|float|string
    {
        if (is_bool($value)) {
            return (int) $value; // engine-portable: SQLite/MySQL store bools as ints
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Scope values must be scalars, null, or lists of scalars; got ' . get_debug_type($value) . '.');
    }

    /**
     * Force the scope onto a new row's column map.
     *
     * Scalar and null entries overwrite whatever the client sent for that
     * column — the scope, not the request, decides the tenant column. A list
     * entry cannot pick a value by itself, so the client's value must already
     * be one of the allowed ones.
     *
     * @param array<string, mixed> $mappedData
     *
     * @return array<string, mixed>
     */
    private function applyScopeToCreateData(array $mappedData): array
    {
        foreach ($this->scope as $column => $value) {
            if (is_array($value)) {
                $sent = $mappedData[$column] ?? null;
                $sent = is_bool($sent) ? (int) $sent : $sent;

                if (!is_scalar($sent)
                    || !in_array((string) $sent, array_map(strval(...), $value), true)) {
                    throw new InvalidArgumentException("Value for '$column' is outside the enforced scope.");
                }

                $mappedData[$column] = $sent;
                continue;
            }

            $mappedData[$column] = $value;
        }

        return $mappedData;
    }

    /**
     * The scope as SQL conditions plus their bindings.
     *
     * @return array{conditions: list<string>, bindings: array<string, int|float|string>}
     */
    private function scopeConditions(?string $alias): array
    {
        $conditions = [];
        $bindings = [];
        $n = 0;

        foreach ($this->scope as $column => $value) {
            $ref = ($alias !== null ? "$alias." : '') . $this->conn->quoteIdentifier($column);

            if ($value === null) {
                $conditions[] = "$ref IS NULL";
                continue;
            }

            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $item) {
                    $param = 'jsonapi_scope_' . $n++;
                    $placeholders[] = ':' . $param;
                    $bindings[$param] = $item;
                }
                $conditions[] = "$ref IN (" . implode(', ', $placeholders) . ')';
                continue;
            }

            $param = 'jsonapi_scope_' . $n++;
            $conditions[] = "$ref = :$param";
            $bindings[$param] = $value;
        }

        return ['conditions' => $conditions, 'bindings' => $bindings];
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private function validateFields(array $fields): void
    {
        $allowedFields = $this->getAllowedFields();
        $invalidFields = array_diff($fields, $allowedFields);
        if ($invalidFields) {
            throw new FieldUnrecognized(array_values($invalidFields));
        }
    }

    private function validateRelationship(string $relationship): void
    {
        $allowedRelationships = $this->getAllowedRelationships();
        if (!in_array($relationship, $allowedRelationships)) {
            throw new InclusionUnrecognized($relationship, "Invalid relationship: $relationship");
        }
    }

    /**
     * @param array<string, mixed> $localBindings
     */
    private function newParamName(array $localBindings = []): string
    {
        return 'p' . (count($this->params) + count($localBindings));
    }

    private function reset(): void
    {
        $this->fields = [];
        $this->sparseFields = [];
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
        // A null size means pagination was switched off, and there is no
        // JSON:API parameter for "no limit" — emitting `page[size]=` would
        // produce an empty value that parses back as a different query.
        if ($this->page['size'] !== null
            && ($this->page['size'] !== 25 || $this->page['number'] !== 1)
        ) {
            $queryParts[] = "page[number]={$this->page['number']}";
            $queryParts[] = "page[size]={$this->page['size']}";
        }

        return $baseUri . ($queryParts ? '?' . implode('&', $queryParts) : '');
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Security Validation Methods
    // ────────────────────────────────────────────────────────────────────────────────

    /**
     * Validate column/table identifier to prevent SQL injection
     *
     * @param string $identifier The column or table name to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidColumnName(string $identifier): bool
    {
        // Check against basic SQL identifier pattern
        if (!preg_match(self::SQL_IDENTIFIER_PATTERN, $identifier)) {
            return false;
        }

        // Additional check: reject SQL keywords that could be dangerous (as whole words)
        $sqlKeywords = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
            'TRUNCATE', 'EXEC', 'EXECUTE', 'UNION', 'OR', 'AND', '--', '/*', '*/',
            'INFORMATION_SCHEMA', 'SYS', 'SYSTEM', 'DUAL'
        ];

        // Split by dots and check each part as a complete word
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            if (in_array(strtoupper(trim($part)), $sqlKeywords, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate HAVING condition for basic security
     *
     * @param string $condition The HAVING clause condition
     * @return bool True if valid, false otherwise
     */
    private function isValidHavingCondition(string $condition): bool
    {
        // Allow basic aggregation functions, comparisons, and parameter placeholders
        $allowedPattern = '/^[a-zA-Z0-9_\s\(\)\.,=<>!:\*]+$/';
        
        if (!preg_match($allowedPattern, $condition)) {
            return false;
        }

        // Check for dangerous SQL patterns
        $dangerousPatterns = [
            '/\b(DROP|DELETE|INSERT|UPDATE|ALTER|CREATE|TRUNCATE)\b/i',
            '/\b(EXEC|EXECUTE|SYSTEM|SHELL)\b/i',
            '/\b(INFORMATION_SCHEMA|SYS)\b/i',
            '/-{2,}/', // SQL comments
            '/\/\*.*?\*\//', // Block comments  
            '/\bUNION\b/i',
            '/\bOR\b.*\b1\s*=\s*1\b/i', // Common injection pattern
            '/;/', // Statement terminators
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize LIKE patterns to prevent certain injection attempts
     *
     * @param string $pattern The LIKE pattern to sanitize
     * @return string The sanitized pattern
     */
    private function sanitizeLikePattern(string $pattern): string
    {
        if (strlen($pattern) > 255) {
            throw new InvalidArgumentException('LIKE pattern too long - maximum 255 characters allowed');
        }

        return $pattern;
    }

    /**
     * Calculate the depth of nested filter structures
     *
     * @param mixed $value The filter value to analyze
     * @param int $currentDepth Current recursion depth
     * @return int Maximum depth found
     */
    private function getFilterDepth(mixed $value, int $currentDepth = 0): int
    {
        if (!is_array($value)) {
            return $currentDepth;
        }

        $maxDepth = $currentDepth;
        foreach ($value as $item) {
            if (is_array($item)) {
                $depth = $this->getFilterDepth($item, $currentDepth + 1);
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
    }

    /**
     * Create a safer expression builder wrapper with additional validation
     *
     * @return SafeExpressionBuilder
     */
    private function getSafeExpressionBuilder(): SafeExpressionBuilder
    {
        return new SafeExpressionBuilder($this->expr);
    }
}