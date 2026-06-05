# Query Builder

`JsonApiQueryBuilder` translates JSON:API query parameters into database queries using Doctrine DBAL directly. It is the read path of the library: you give it parsed query parameters and an operation, and it returns rows already shaped for JSON:API serialization.

## Design

The builder works against the DBAL `Connection` rather than hydrating full ORM entities, and selects only the columns implied by the resource's `fields` and any sparse fieldset. This keeps result sets narrow and avoids hydrating objects you do not need for a read.

It is constructed per request, for one resource class:

```php
use Modufolio\JsonApi\JsonApiQueryBuilder;

$builder = new JsonApiQueryBuilder(
    $config,                      // array — from JsonApiConfigurator::buildConfig()
    $entityManager,               // Doctrine\ORM\EntityManagerInterface
    $entityManager->getConnection(), // Doctrine\DBAL\Connection
    Article::class,               // the resource (entity) class this builder serves
    $filterRegistry,              // optional Modufolio\JsonApi\Filter\FilterRegistry
);
```

The fifth argument is optional; without it, no filters are applied.

## The two ways to drive it

### From a parsed request (the common case)

`JsonApiUrlParser` reads the request into a `JsonApiQueryParams`, and `applyParams()` loads all of it — filter, sort, include, page, group, having, sparse fields, id — in one call. You then set the operation and execute:

```php
$params = (new JsonApiUrlParser($config))->parse($request, Article::class);

$result = $builder
    ->applyParams($params)
    ->operation('index')
    ->withTotalCount()
    ->get();
```

### By hand

The same parts can be set individually. Every setter takes an array (or scalars for `page`) and replaces that part of the query:

```php
$result = $builder
    ->fields(['articles' => ['title', 'content']])   // sparse fieldsets, keyed by resource type
    ->include(['author', 'category', 'tags'])         // relationships to include
    ->filter(['status' => ['eq' => 'published']])     // WHERE conditions
    ->sort(['-publishedAt', 'title'])                 // ORDER BY (— prefix = descending)
    ->page(1, 20)                                     // LIMIT / OFFSET
    ->operation('index')
    ->get();
```

## Fluent method reference

| Method | Purpose |
|--------|---------|
| `applyParams(JsonApiQueryParams $params): self` | Load filter, sort, include, page, group, having, fields and id in one call |
| `fields(array $fields): self` | Sparse fieldsets, keyed by resource type: `['articles' => ['id','title']]` |
| `filter(array $filters): self` | Filter conditions (see [Filtering](filtering.md)) |
| `sort(array $sort): self` | Sort fields; `['field' => 'ASC']` or list form `['-field']` |
| `include(array $includes): self` | Relationships to include |
| `page(int $number, int $size): self` | Pagination — page number and page size |
| `group(string $field): self` | Add a `GROUP BY` field |
| `having(string $condition, array $bindings = []): self` | Add a `HAVING` clause |
| `operation(string $operation): self` | `index`, `show`, `create`, `update`, `delete` — checked against the resource's `operations` map |
| `withId(string $id): self` | Target a single resource (used by `show`) |
| `withData(array $data): self` | Supply data for mutations |
| `withTotalCount(): self` | Include the total row count in the result |
| `debug(): self` | Return the query and bindings instead of executing |
| `get(): array` | Execute and return the rows |

### Aggregates

```php
$builder->count();           // int
$builder->max('price');      // float
$builder->min('price');      // float
$builder->sum('price');      // float
$builder->avg('price');      // float
```

### Inspecting the query

```php
$builder->toSql();           // string — the generated SQL (throws on failure)
$builder->getQueryBuilder(); // the underlying Doctrine\DBAL\Query\QueryBuilder
$builder->expr();            // Doctrine\DBAL\Query\Expression\ExpressionBuilder
$builder->buildUri();        // string — the JSON:API URI for the current query
```

## Operations

### Index — collection

```php
// GET /articles?filter[status]=published&sort=-publishedAt&include=author
$params = $parser->parse($request, Article::class);

$result = $builder
    ->applyParams($params)
    ->operation('index')
    ->withTotalCount()
    ->get();

// $result = ['data' => [ ...rows... ], 'total' => 50]
```

Each row is an associative array with `id`, `attributes`, and (when relationships are included) `relationships`. You wrap these into `ResourceObject`s for the response document — see [Basic Usage](basic-usage.md).

### Show — single resource

```php
// GET /articles/123
$params = $parser->parse($request, Article::class);
$params->id = '123';

$result = $builder
    ->applyParams($params)
    ->operation('show')
    ->get();

// $result = [ {row} ]  — an empty array if the id was not found
```

## Filtering

Filter values are operator maps. Supported operators: `eq`, `neq`/`not`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`.

```php
// Range — WHERE published_at >= ? AND published_at < ?
->filter([
    'publishedAt' => ['gte' => '2023-01-01', 'lt' => '2024-01-01'],
    'status'      => ['eq' => 'published'],
])

// IN — WHERE status IN (?, ?)
->filter(['status' => ['in' => ['published', 'draft']]])

// Negation — WHERE status != ?
->filter(['status' => ['not' => 'archived']])
```

A filter key must be one of the resource's configured `fields`; when the query comes through `JsonApiUrlParser`, unknown keys are dropped. Filtering on a related resource's column (e.g. `author.name`) is not supported through the request parser. See the [Filtering](filtering.md) guide for custom filters such as `SearchFilter` and `DateFilter`.

## Sorting

```php
->sort(['title'])                       // ORDER BY title ASC
->sort(['-publishedAt'])                // ORDER BY published_at DESC
->sort(['status', '-publishedAt'])      // multiple keys, left to right
```

Sort fields must be in the resource's `fields` list. Sorting across a relationship is not supported.

## Includes

```php
->include(['author'])                       // single
->include(['author', 'comments', 'tags'])   // multiple
->include(['author', 'comments.author'])    // nested
```

Each include must be in the resource's `relationships` list. Keep nesting shallow — two levels (`comments.author`) is a reasonable ceiling.

## Pagination

```php
// GET /articles?page[number]=2&page[size]=20
->page(2, 20)
```

Only page-number / page-size pagination is supported; the parser defaults to page 1, size 10. There is no offset/limit form. Pair `page()` with `withTotalCount()` when you need the total for pagination metadata and links.

## Sparse fieldsets

```php
// GET /articles?fields[articles]=id,title,publishedAt&fields[authors]=name
->fields([
    'articles' => ['id', 'title', 'publishedAt'],
    'authors'  => ['name'],
])
```

Fieldsets are keyed by resource type and narrow the `SELECT` to the requested columns (intersected with each resource's `fields` allow-list).

## Inspecting the generated SQL

```php
$sql = $builder
    ->filter(['status' => ['eq' => 'published']])
    ->include(['author', 'category'])
    ->sort(['-publishedAt'])
    ->page(1, 20)
    ->operation('index')
    ->toSql();
```

Or use `debug()` to get the query and its bindings back from `get()` instead of executing it.

## Security notes

- **Keep `fields` explicit** — it is the allow-list for serialization, filtering, sorting, and sparse fieldsets. Anything not listed cannot be reached.
- **Restrict `operations`** — set the operations map to `['index' => true, 'show' => true]` for a read-only API.
- **Limit `relationships`** — only listed associations can be included.

## Next steps

- [Filtering](filtering.md) — operators, search strategies, and custom filters
- [Configuration](configuration.md) — the configuration reference
- [API Reference](reference/index.md) — every public class and method
