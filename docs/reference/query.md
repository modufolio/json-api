# Reference: Request → Query

Namespace: `Modufolio\JsonApi`

## JsonApiUrlParser

Turns a PSR-7 request into a validated `JsonApiQueryParams`, using the resource config's `fields` / `relationships` as allow-lists.

```php
public function __construct(array $config)
public function parse(ServerRequestInterface $request, string $entityClass): JsonApiQueryParams
```

`parse()` reads `fields[type]`, `filter`, `include`, `sort`, `page[number]` / `page[size]`, `group`, `having`, and the route attribute `id`. It throws `InvalidArgumentException` if `$entityClass` is not in the config. Sort fields and filter keys outside `fields` are silently dropped. Note an asymmetry: a sort field is snake_case-converted before it is checked (`?sort=published_at` matches the `publishedAt` field), while `group` is not — `?group=published_at` is dropped where `?group=publishedAt` is kept. Only known operators survive: the standard set (`eq`, `neq`, `not`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`) plus the `DateFilter` range operators (`after`, `before`, `strictly_after`, `strictly_before`).

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
public function fields(array $fields): self                          // ['type' => ['col', ...]]; narrows included resources too
public function filter(array $filters): self
public function sort(array $sort): self                              // ['field' => 'ASC'] or ['-field']
public function include(array $includes): self                       // 'rel' or 'rel.toOneRel'
public function page(int $number, int $size): self
public function withoutPagination(): self                            // emit no LIMIT at all
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

Return shape depends on the operation:

| Operation | Shape |
|-----------|-------|
| `index` | `['data' => [...rows...], 'included' => [...]]`, plus `'total' => int` when `withTotalCount()` was called |
| `show` | `[0 => row, 'included' => [...]]` — the row sits at the numeric key `0`, not under `data`; `[]` when the id matched nothing |
| `delete` | `['status' => 'deleted', 'id' => ...]` |
| any, after `debug()` | `['query' => string, 'bindings' => array]` |

`included` is always present on `index` and `show` (empty when nothing was included). Each row is `['id' => ..., 'attributes' => [...], 'relationships' => [...]]`; `relationships` carries linkage for every configured to-many whether or not it was included, and for any to-one whose foreign key was selected.

**Pagination is unconditional.** Without `page()` the builder uses its own default of size 25 — note `JsonApiQueryParams` defaults to 10 instead, so a query built from a parsed request and one built by hand paginate differently. `withoutPagination()` is the only way to get every row.

**Includes** resolve by cardinality: a to-one is `LEFT JOIN`ed into the main query and contributes every field its resource exposes (aliased `rel_field` onto the parent's attributes); a to-many — `OneToMany` and `ManyToMany` alike — is resolved afterwards by one batched `WHERE fk IN (…)`, so it never multiplies rows or distorts `LIMIT`. Nesting is one level deep onto a to-one (`comments.author`); an unknown segment, a to-many second segment, or a third segment throws `InvalidArgumentException`.

`withTotalCount()` counts with `COUNT(DISTINCT id)`, so a join in the built query cannot inflate the total.

### Aggregates

```php
public function count(): int
public function max(string $column): float
public function min(string $column): float
public function sum(string $column): float
public function avg(string $column): float
```

Aggregates ignore pagination — they run the built query with its limit and offset stripped. All four of `max`/`min`/`sum`/`avg` cast to `float`, so they are meaningful only over numeric columns: a `max()` over a date or string column returns `0.0` rather than the value.

### Inspection

```php
public function toSql(): string                       // throws on an invalid field/relationship/operation
public function getQueryBuilder(): QueryBuilder        // Doctrine\DBAL\Query\QueryBuilder
public function expr(): ExpressionBuilder
public function buildUri(): string
```

`buildUri()` reconstructs the JSON:API query string. It omits pagination when the query is on page 1 at the builder's default size, and after `withoutPagination()` — there is no JSON:API parameter meaning "no limit", so the round-trip is lossy in that one case.

### Example

```php
$result = (new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class, $registry))
    ->applyParams($parser->parse($request, Article::class))
    ->operation('index')
    ->withTotalCount()
    ->get();
```
