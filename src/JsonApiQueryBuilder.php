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
use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Platform\SqlDialect;
use Modufolio\JsonApi\Query\BuiltInFilterCompiler;
use Modufolio\JsonApi\Query\ResourceSchema;
use Modufolio\JsonApi\Query\ResourceWriter;
use Modufolio\JsonApi\Query\RowScope;
use Modufolio\JsonApi\Query\RowTransformer;
use Modufolio\JsonApi\Query\SchemaRegistry;
use Modufolio\JsonApi\Query\SqlGuard;
use Modufolio\JsonApi\Query\ToManyIncludeLoader;
use Modufolio\JsonApi\Query\ToOneJoinBuilder;

/**
 * The fluent entry point for reading and writing one resource type.
 *
 * Collects JSON:API query parameters and the operation to run, then composes
 * the SQL from the components in {@see \Modufolio\JsonApi\Query}: the schema
 * says what the resource exposes, the scope says which rows may be touched,
 * the join builder and include loader resolve relationships, the transformer
 * shapes rows into resource objects and the writer builds the mutations.
 * This class owns the request state, the SELECT itself and the five
 * operation paths.
 */
final class JsonApiQueryBuilder
{
    private QueryBuilder $qb;
    private ExpressionBuilder $expr;
    private readonly ResourceSchema $schema;
    /** @var ClassMetadata<object> */
    private readonly ClassMetadata $meta;
    private readonly ToOneJoinBuilder $joins;
    private readonly BuiltInFilterCompiler $builtInFilters;
    private readonly ToManyIncludeLoader $toMany;
    private readonly RowTransformer $transformer;
    private readonly ResourceWriter $writer;
    /**
     * Deliberately NOT rebuilt by reset(): the scope describes which rows the
     * caller may touch at all, so a reused builder must keep it — forgetting a
     * security constraint on reuse would fail open. See {@see RowScope}.
     */
    private readonly RowScope $scope;

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
     * @param array<string, mixed>  $config
     * @param class-string          $resourceClass
     */
    public function __construct(
        array $config,
        EntityManagerInterface $em,
        private readonly Connection $conn,
        private readonly string $resourceClass,
        private readonly ?FilterRegistry $filterRegistry = null
    ) {
        $schemas = new SchemaRegistry($config, $em);
        $this->schema = $schemas->of($resourceClass);
        $this->meta = $this->schema->metadata();
        $this->scope = new RowScope($this->schema, $conn);
        $this->joins = new ToOneJoinBuilder($schemas, $this->schema);
        $this->builtInFilters = new BuiltInFilterCompiler($this->schema);
        $this->transformer = new RowTransformer($schemas);
        $this->toMany = new ToManyIncludeLoader($conn, $schemas, $this->schema, $this->transformer);
        $this->writer = new ResourceWriter($conn, $this->schema, $this->scope);
        $this->freshQueryBuilder();
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // JSON:API Query Parameters
    // ────────────────────────────────────────────────────────────────────────────────

    public function applyParams(JsonApiQueryParams $params): self
    {
        if ($params->sparseFields) {
            // The full per-type map: narrows included resources as well as
            // the primary one.
            $this->fields($params->sparseFields);
        } elseif ($params->fields) {
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
        if (!empty($fields) && is_array(reset($fields))) {
            // Retain the whole map: the join builder and include loader
            // narrow included resources with it.
            $this->sparseFields = $fields;
            $resourceKey = $this->schema->configuredResourceKey();
            /** @var list<string> $selected */
            $selected = $resourceKey !== null && isset($fields[$resourceKey])
                ? array_values($fields[$resourceKey])
                : []; // No fields specified for this resource: use all fields
        } else {
            /** @var list<string> $selected */
            $selected = array_values($fields);
        }

        $this->fields = $selected;

        if ($selected !== []) {
            $this->schema->assertFields($selected);
        }

        return $this;
    }

    /**
     * @param array<array-key, mixed> $filters
     */
    public function filter(array $filters): self
    {
        $this->schema->assertFields(array_keys($filters));
        $this->filters = $filters;
        return $this;
    }

    /**
     * @param array<array-key, string> $sort
     */
    public function sort(array $sort): self
    {
        foreach ($this->parseSort($sort) as [$field]) {
            $this->schema->assertFields([$field]);
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
            $this->schema->assertRelationship(explode('.', $path)[0]);
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
        $this->schema->assertFields([$field]);
        $column = $this->schema->columnName($field);
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
        if (!SqlGuard::isSafeHavingCondition($condition)) {
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
        $allowedOperations = $this->schema->allowedOperations();
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
        $this->scope->add($scope);

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
        $this->scope->waive();

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
        // Aggregates leak just as much as rows: an unscoped COUNT tells you how
        // many records the other tenants have.
        $this->scope->assertSatisfied();

        if ($column !== '*') {
            $this->schema->assertFields([$column]);
        }

        $this->buildQuery();
        $qb = $this->bindParams(clone $this->qb);
        $expr = $column === '*'
            ? "$method(*)"
            : "$method($this->alias.{$this->conn->quoteIdentifier($this->schema->columnName($column))})";
        $qb->select("$expr AS aggregation")
            ->setMaxResults(null)
            ->setFirstResult(0);

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
        $this->scope->assertSatisfied();
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
            return $this->debugOutput($this->bindParams(clone $this->qb));
        }

        $rawData = $this->bindParams($this->qb)->executeQuery()->fetchAllAssociative();

        $data = array_map(fn ($row) => $this->transformer->primary($row, $this->schema), $rawData);

        // To-many linkage (and included data for requested rels) via separate IN-queries.
        $toMany = $this->toMany->load(array_column($rawData, 'id'), $this->includes, $this->sparseFields);
        $data = $toMany->attachTo($data);
        $included = $toMany->resources();

        if (!$this->withTotalCount) {
            return ['data' => $data, 'included' => $included];
        }

        return [
            'total' => $this->fetchTotalCount(),
            'data' => $data,
            'included' => $included,
        ];
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
            return $this->debugOutput($this->bindParams(clone $this->qb));
        }

        $rawData = $this->bindParams($this->qb)->executeQuery()->fetchAssociative();

        if (!$rawData) {
            // No such record. The caller turns a null `data` into a 404 — the
            // spec has no document shape for a missing single resource, and a
            // bare [] could not be told apart from a malformed result.
            return ['data' => null];
        }

        $item = $this->transformer->primary($rawData, $this->schema);

        $toMany = $this->toMany->load([$this->id], $this->includes, $this->sparseFields);
        [$item] = $toMany->attachTo([$item]);

        // A single resource is the `data` member itself, not a one-element
        // list — the same split every JSON:API implementation makes between a
        // resource document and a collection document.
        return ['data' => $item, 'included' => $toMany->resources()];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeCreate(): array
    {
        $qb = $this->writer->insert($this->data);

        if ($this->debug) {
            return $this->debugOutput($qb);
        }

        $qb->executeStatement();
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

        $qb = $this->writer->update($this->id, $this->data);

        if ($this->debug) {
            return $this->debugOutput($qb);
        }

        $qb->executeStatement();
        // The scoped re-read reports an out-of-scope row exactly like a
        // missing one (`data: null`), so the two cannot be told apart.
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

        $qb = $this->writer->delete($this->id);

        if ($this->debug) {
            return $this->debugOutput($qb);
        }

        if ($qb->executeStatement() === 0) {
            // Nothing matched — the id does not exist, or the scope excludes
            // it; the two are deliberately indistinguishable. Same convention
            // as show: the caller turns a null `data` into a 404.
            return ['data' => null];
        }

        return ['status' => 'deleted', 'id' => $this->id];
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
     * id comparison stays unchanged. Unresolvable ids become '-1', which no
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
            ->from($this->schema->tableName())
            ->where($this->conn->quoteIdentifier($this->meta->getColumnName('uuid')) . ' = :uuid')
            ->setParameter('uuid', $this->id)
            ->executeQuery()
            ->fetchOne();

        $this->id = $resolved === false ? '-1' : (string) $resolved;
    }

    /**
     * The statement and bindings a debug() run returns instead of executing.
     *
     * @return array{query: string, bindings: array<int|string, mixed>}
     */
    private function debugOutput(QueryBuilder $qb): array
    {
        return [
            'query' => $qb->getSQL(),
            'bindings' => $qb->getParameters(),
        ];
    }

    /**
     * Set the parameters collected while building onto a query builder.
     */
    private function bindParams(QueryBuilder $qb): QueryBuilder
    {
        foreach ($this->params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return $qb;
    }

    // ────────────────────────────────────────────────────────────────────────────────
    // Query Building Methods
    // ────────────────────────────────────────────────────────────────────────────────

    private function buildQuery(): void
    {
        // Start over each time to avoid alias conflicts from a previous build.
        $this->freshQueryBuilder();
        $this->params = [];

        $this->qb->select(...$this->buildSelect());

        foreach ($this->joins->build($this->includes, $this->sparseFields, $this->alias) as $join) {
            $this->qb->leftJoin($join['alias'], $join['table'], $join['joinAlias'], $join['condition']);
            $this->qb->addSelect(...$join['select']);
        }

        // Filters are applied to the query builder directly; only their
        // bindings come back.
        $this->params = array_merge($this->params, $this->buildFilters());

        // Row-level scope (see scope()). Applied after client filters so a
        // crafted filter can only narrow the scoped set, never widen it.
        $scope = $this->scope->conditions($this->alias);
        foreach ($scope['conditions'] as $condition) {
            $this->qb->andWhere($condition);
        }
        $this->params = array_merge($this->params, $scope['bindings']);

        if ($this->groupBy) {
            $this->qb->addGroupBy($this->groupBy);
        }

        if ($this->having && $this->having['query']) {
            $this->qb->having($this->having['query']);
            $this->params = array_merge($this->params, $this->having['bindings']);
        }

        $dialect = SqlDialect::for($this->conn->getDatabasePlatform());
        foreach ($this->parseSort($this->sort) as [$field, $direction]) {
            // Each engine has its own idea of where NULLs belong, so the same
            // sort returns a different first page on each. The dialect pins
            // them to the end everywhere.
            $expression = "$this->alias.{$this->schema->columnName($field)}";
            foreach ($dialect->orderByNullsLast($expression, $direction) as $orderBy) {
                $this->qb->addOrderBy($orderBy);
            }
        }

        // A null size means pagination was explicitly switched off.
        if ($this->page['size'] !== null) {
            $this->qb->setFirstResult(($this->page['number'] - 1) * $this->page['size']);
            $this->qb->setMaxResults($this->page['size']);
        }
    }

    /**
     * The resource's own columns, plus the foreign key of every allowed
     * to-one relationship as `_rel_<name>_id` for relationship linkage.
     *
     * @return list<string>
     */
    private function buildSelect(): array
    {
        $select = [];

        foreach ($this->fields ?: $this->schema->allowedFields() as $field) {
            $column = $this->schema->columnName($field);
            $select[] = "$this->alias.{$this->conn->quoteIdentifier($column)} AS $column";
        }

        foreach ($this->schema->allowedRelationships() as $relationship) {
            if (!$this->meta->hasAssociation($relationship)) {
                continue;
            }
            $mapping = $this->meta->getAssociationMapping($relationship);
            // ManyToOne and OneToOne (owning side) carry the foreign key here.
            if (($mapping['type'] & ClassMetadata::TO_ONE) && isset($mapping['joinColumns'])) {
                $fkColumn = $mapping['joinColumns'][0]['name'] ?? $relationship . '_id';
                $select[] = "$this->alias.{$this->conn->quoteIdentifier($fkColumn)} AS _rel_{$relationship}_id";
            }
        }

        return $select;
    }

    /**
     * @return array<string, mixed> The filter bindings.
     */
    private function buildFilters(): array
    {
        if ($this->filterRegistry && $this->filterRegistry->hasFilters($this->resourceClass)) {
            return $this->filterRegistry->applyFilters(
                $this->resourceClass,
                $this->qb,
                $this->filters,
                $this->meta->fieldMappings,
                $this->alias
            );
        }

        return $this->builtInFilters->apply($this->qb, $this->alias, $this->filters, count($this->params));
    }

    /**
     * Normalise the two accepted sort shapes — `['field', '-field']` and
     * `['field' => 'ASC', 'other' => 'DESC']` — to field/direction pairs.
     *
     * @param array<array-key, string> $sort
     *
     * @return list<array{string, 'ASC'|'DESC'}>
     */
    private function parseSort(array $sort): array
    {
        $parsed = [];

        foreach ($sort as $key => $value) {
            if (is_string($key) && in_array(strtoupper($value), ['ASC', 'DESC'], true)) {
                $direction = strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                $parsed[] = [$key, $direction];
            } elseif (str_starts_with($value, '-')) {
                $parsed[] = [substr($value, 1), 'DESC'];
            } else {
                $parsed[] = [$value, 'ASC'];
            }
        }

        return $parsed;
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

        return (int)$this->bindParams($countQb)->executeQuery()->fetchOne();
    }

    private function freshQueryBuilder(): void
    {
        $this->qb = $this->conn->createQueryBuilder();
        $this->expr = $this->qb->expr();
        $this->qb->from($this->schema->tableName(), $this->alias);
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
        $this->freshQueryBuilder();
    }

    public function buildUri(): string
    {
        $resourceKey = $this->schema->resourceKey();
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
}
