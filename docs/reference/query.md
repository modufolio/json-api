# Reference: Request → Query

Namespace: `Modufolio\JsonApi`

## JsonApiUrlParser

Turns a PSR-7 request into a validated `JsonApiQueryParams`, using the resource config's `fields` / `relationships` as allow-lists.

```php
public function __construct(array $config)
public function parse(ServerRequestInterface $request, string $entityClass): JsonApiQueryParams
```

`parse()` reads `fields[type]`, `filter`, `include`, `sort`, `page[number]` / `page[size]`, `group`, `having`, and the route attribute `id`. It throws `InvalidArgumentException` if `$entityClass` is not in the config. Sort fields and filter keys outside `fields` are silently dropped; only known operators survive — the standard set (`eq`, `neq`, `not`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`) plus the `DateFilter` range operators (`after`, `before`, `strictly_after`, `strictly_before`).

## JsonApiQueryParams

A plain value object; every property is public and writable.

```php
public function __construct(
    public array $fields = [],
    public array $filter = [],
    public array $include = [],
    public array $sort = [],
    public array $page = ['number' => 1, 'size' => 10],
    public array $group = [],
    public array $having = ['query' => '', 'bindings' => []],
    public ?string $id = null,
)
```

You can mutate it after parsing, e.g. `$params->id = '123';` before a `show`.

## JsonApiQueryBuilder

`final class`. Built per request for a single resource class; runs queries through Doctrine DBAL.

```php
public function __construct(
    array $config,
    EntityManagerInterface $em,
    Connection $conn,
    string $resourceClass,                 // readonly
    ?FilterRegistry $filterRegistry = null,
)

public ?string $id = null;
```

### Building the query

```php
public function applyParams(JsonApiQueryParams $params): self
public function fields(array $fields): self                          // ['type' => ['col', ...]]
public function filter(array $filters): self
public function sort(array $sort): self                              // ['field' => 'ASC'] or ['-field']
public function include(array $includes): self
public function page(int $number, int $size): self
public function group(string $field): self
public function having(string $condition, array $bindings = []): self
```

### Operation & data

```php
public function operation(string $operation): self   // index|show|create|update|delete
public function withId(string $id): self
public function withData(array $data): self
public function withTotalCount(): self
public function debug(): self                          // return query + bindings instead of executing
```

### Execution

```php
public function get(): array
```

Returns the rows. With `withTotalCount()`, the shape is `['data' => [...], 'total' => int]`; otherwise a list of rows. Each row has `id`, `attributes`, and (when included) `relationships`.

### Aggregates

```php
public function count(): int
public function max(string $column): float
public function min(string $column): float
public function sum(string $column): float
public function avg(string $column): float
```

### Inspection

```php
public function toSql(): string                       // throws Exception on failure
public function getQueryBuilder(): QueryBuilder        // Doctrine\DBAL\Query\QueryBuilder
public function expr(): ExpressionBuilder
public function buildUri(): string
```

### Example

```php
$result = (new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class, $registry))
    ->applyParams($parser->parse($request, Article::class))
    ->operation('index')
    ->withTotalCount()
    ->get();
```
