# Basic Usage

This guide demonstrates the fundamental usage patterns of the Modufolio JSON:API library through practical examples.

## How a request flows

There is no single "do everything" call. A request flows through four steps:

1. **Parse** the request into `JsonApiQueryParams` with `JsonApiUrlParser::parse()`.
2. **Build and run** the query with `JsonApiQueryBuilder` — `applyParams()`, then `operation()`, then `get()`.
3. **Assemble** a `JsonApiDocument` from `ResourceObject`s.
4. **Emit** the document as JSON.

The [`JsonApiController`](https://github.com/modufolio/json-api) test fixture wires all four together for every operation; the snippets below show each step on its own.

## Quick start

```php
<?php

use App\Entity\Article;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;
use Nyholm\Psr7\ServerRequestFactory;

// Your Doctrine EntityManager
$entityManager = /* your entity manager */;

// 1. Build the config
$configurator = new JsonApiConfigurator();
$configurator->resource(Article::class)
    ->key('articles')
    ->fields(['id', 'title', 'content', 'publishedAt'])
    ->relationships(['author', 'comments'])
    ->operations([
        'index'  => true, 'show'   => true, 'create' => true,
        'update' => true, 'delete' => true,
    ]);
$config = $configurator->buildConfig();

// A filter registry (here, the default handler for every entity)
$registry = new FilterRegistry();
foreach (array_keys($config) as $entityClass) {
    $registry->register($entityClass, new JsonApiFilterHandler());
}

// 2. Parse the request
$request = ServerRequestFactory::fromGlobals();
$params  = (new JsonApiUrlParser($config))->parse($request, Article::class);

// 3. Build and run the query
try {
    $result = (new JsonApiQueryBuilder(
        $config,
        $entityManager,
        $entityManager->getConnection(),
        Article::class,
        $registry,
    ))
        ->applyParams($params)
        ->operation('index')
        ->withTotalCount()
        ->get();

    // 4. Assemble and emit the document
    $document = new JsonApiDocument();
    $document->setData(array_map(
        fn (array $row) => (new ResourceObject('articles', (string) $row['id']))
            ->setAttributes($row['attributes'] ?? []),
        $result['data'] ?? $result,
    ));
    $document->setMeta(['total' => $result['total'] ?? 0]);

    header('Content-Type: application/vnd.api+json');
    echo json_encode($document->toArray());
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => $e->getMessage()]],
    ]);
}
```

## Core operations

Each operation differs only in the `operation()` you set, the id/data you supply, and how you build the response document. The query builder constructor is the same five arguments every time — abbreviated as `$builder = new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class, $registry)` below.

### Index — `GET /articles`

```php
$params = $parser->parse($request, Article::class);

$result = $builder
    ->applyParams($params)   // filter, sort, include, page, sparse fields
    ->operation('index')
    ->withTotalCount()
    ->get();
// $result = ['data' => [...rows...], 'total' => int]
```

### Show — `GET /articles/123`

```php
$params = $parser->parse($request, Article::class);
$params->id = '123';   // or arrives via the route attribute "id"

$result = $builder
    ->applyParams($params)
    ->operation('show')
    ->get();
// $result = [ {row} ]  — empty array if not found
```

### Create — `POST /articles`

Mutations go through `InputNormalizer`, which accepts both JSON:API and plain-JSON bodies. You then populate and persist the entity yourself (validate with Symfony Validator):

```php
use Modufolio\JsonApi\InputNormalizer;

$payload    = json_decode((string) $request->getBody(), true);
$normalizer = new InputNormalizer();
$normalized = $normalizer->normalize($payload, $request->getHeaderLine('Content-Type'), 'articles');
$data       = $normalizer->mergeData($normalized);   // flat [field => value]

$article = new Article();
// ... set fields from $data, persist, flush ...
```

### Update — `PATCH /articles/123`

Load the entity, normalize the body the same way, apply the changed fields, then `flush()`.

### Delete — `DELETE /articles/123`

Load the entity and `remove()` it (or call your own `softDelete()`), then return an empty `204` via `ResponseFactory::empty(204)`.

> The library deliberately does **not** hide persistence behind a magic `buildFromRequest()` call. Reads go through the query builder; writes are ordinary Doctrine `persist`/`flush` after normalizing the payload. See the `JsonApiController` fixture for a complete, copy-pasteable implementation of all five operations plus `related`.

## Working with query parameters

`JsonApiUrlParser::parse()` reads these parameters from the request and validates them against the resource's `fields` / `relationships` allow-lists.

### Filtering

```php
// Simple equality — GET /articles?filter[status]=published
['filter' => ['status' => 'published']]

// Multiple fields — GET /articles?filter[status]=published&filter[author]=john
['filter' => ['status' => 'published', 'author' => 'john']]

// Operators — GET /articles?filter[publishedAt][gte]=2023-01-01&filter[publishedAt][lt]=2024-01-01
['filter' => ['publishedAt' => ['gte' => '2023-01-01', 'lt' => '2024-01-01']]]

// IN — GET /articles?filter[status][in]=published,draft  (or repeated filter[status][]=)
['filter' => ['status' => ['published', 'draft']]]   // a list array is normalized to ['in' => [...]]
```

Supported operators: `eq`, `neq`/`not`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`. Filters on fields not in the `fields` allow-list are dropped.

### Sorting

```php
['sort' => 'title']                    // GET /articles?sort=title          (ascending)
['sort' => '-publishedAt']             // GET /articles?sort=-publishedAt   (descending)
['sort' => 'status,-publishedAt,title']// multiple fields, left to right
```

Each sort field must appear in the resource's `fields` list (snake_case in the URL is matched against the camelCase field name). Sorting across a relationship (e.g. `author.name`) is **not** supported — only the resource's own fields.

### Pagination

```php
// Page-based — GET /articles?page[number]=2&page[size]=20
['page' => ['number' => '2', 'size' => '20']]
```

Only `page[number]` and `page[size]` are recognised; the default is page 1, size 10. There is no offset/limit form.

### Sparse fieldsets

```php
// GET /articles?fields[articles]=id,title,publishedAt
['fields' => ['articles' => 'id,title,publishedAt']]

// With an include — GET /articles?include=author&fields[articles]=title&fields[authors]=name
['include' => 'author', 'fields' => ['articles' => 'title', 'authors' => 'name']]
```

Requested fields are intersected with the `fields` allow-list.

### Including relationships

```php
['include' => 'author']                 // single
['include' => 'author,comments']        // multiple
['include' => 'author,comments.author'] // nested
```

Each include must be in the resource's `relationships` allow-list.

## Next steps

- [Query Builder](query-builder.md) — the full query building API
- [Filtering](filtering.md) — advanced filtering and custom filters
- [Configuration](configuration.md) — complete configuration reference
- [API Reference](reference/index.md) — every public class and method
