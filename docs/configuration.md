# Configuration Reference

This document is a reference for configuring the Modufolio JSON:API library: how resources are declared, which configuration keys the library actually reads, and how filtering, sorting, inclusion, and pagination are controlled.

## How configuration works

Configuration is normally written with the fluent `JsonApiConfigurator` API in a file that returns a closure:

```php
<?php
// config/json_api.php

use App\Entity\Article;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    $api->resource(Article::class)
        ->key('articles')
        ->fields(['id', 'title', 'content', 'publishedAt'])
        ->relationships(['author', 'comments', 'tags'])
        ->operations([
            'index'  => true,
            'show'   => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ]);
};
```

You load it by invoking the closure with a configurator and calling `buildConfig()`:

```php
$configurator = new JsonApiConfigurator();
(require 'config/json_api.php')($configurator);

$config = $configurator->buildConfig();   // the plain array consumed by the rest of the library
```

`buildConfig()` returns a plain array keyed by entity class. Each entry has exactly four keys:

```php
$config = [
    App\Entity\Article::class => [
        'resource_key'  => 'articles',
        'fields'        => ['id', 'title', 'content', 'publishedAt'],
        'relationships' => ['author', 'comments', 'tags'],
        'operations'    => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
    ],
];
```

> **These four keys — `resource_key`, `fields`, `relationships`, `operations` — are the only keys the library reads.** There are no `filters`, `sorts`, `includes`, or `pagination` configuration keys. Filtering is configured separately through a `FilterRegistry` (see [Filters](#filters)); sorting, inclusion, and pagination are request-time concerns described below.

## Configuring resources from the entity

As an alternative to the fluent API, an entity can describe itself by implementing `JsonApiResource` and you register it with `addEntity()` (or `entities()`):

```php
use Modufolio\JsonApi\JsonApiResource;

class Article implements JsonApiResource
{
    public static function getResourceKey(): string { return 'articles'; }
    public static function getApiFields(): array { return ['id', 'title', 'content', 'publishedAt']; }
    public static function getApiRelationships(): array { return ['author', 'comments', 'tags']; }
    public static function getApiOperations(): array { return ['index' => true, 'show' => true]; }
    public function getId(): mixed { return $this->id; }
}
```

```php
$api->addEntity(Article::class);   // buildConfig() reads the static methods above
```

The fluent `resource()` API and the entity-based `addEntity()` API produce the same config array. Use whichever you prefer; you can mix them.

## Configuration keys

### resource_key

**Set with:** `->key('articles')` &nbsp;·&nbsp; **Type:** `string` &nbsp;·&nbsp; **Required:** Yes

The JSON:API resource type identifier used in URLs and in the `"type"` member of every resource object.

```php
$api->resource(Article::class)->key('articles');
// → URLs like /articles, resource objects with "type": "articles"
```

### fields

**Set with:** `->fields([...])` &nbsp;·&nbsp; **Type:** `string[]` &nbsp;·&nbsp; **Default:** all mapped ORM fields

The allow-list of entity properties exposed through the API. A field listed here may be:

- serialized in response bodies,
- selected via sparse fieldsets (`fields[articles]=id,title`),
- filtered on,
- **sorted on**.

```php
->fields(['id', 'title', 'content', 'publishedAt', 'slug', 'status'])
```

If `fields` is omitted for an entity, the library falls back to every field in the Doctrine metadata. **Always set it explicitly** to avoid exposing sensitive columns.

### relationships

**Set with:** `->relationships([...])` &nbsp;·&nbsp; **Type:** `string[]` &nbsp;·&nbsp; **Default:** all mapped ORM associations

The allow-list of Doctrine associations that may be **included** (via `?include=`) and traversed as related resources.

```php
->relationships(['author', 'comments', 'tags', 'category'])
```

Supported association types: `ManyToOne`, `OneToMany`, `ManyToMany`, `OneToOne`. If omitted, the library falls back to every association in the Doctrine metadata.

### operations

**Set with:** `->operations([...])` &nbsp;·&nbsp; **Type:** `array<string, bool>` &nbsp;·&nbsp; **Default:** `['index' => true]`

An **associative map of operation name to boolean**, not a flat list. The recognised operations are `index`, `show`, `create`, `update`, `delete`.

```php
->operations([
    'index'  => true,    // GET    /articles
    'show'   => true,    // GET    /articles/{id}
    'create' => true,    // POST   /articles
    'update' => true,    // PATCH  /articles/{id}
    'delete' => false,   // DELETE /articles/{id}  — explicitly disabled
])
```

Setting an operation to `false` (or omitting it) disables it. When `operations` is not set at all, only `index` is enabled.

## Sorting, inclusion, and pagination

These are **not** configuration keys — they are request parameters, constrained by the `fields` and `relationships` allow-lists.

| Concern | Request parameter | Constrained by | Parsed into |
|---------|-------------------|----------------|-------------|
| Sorting | `?sort=-publishedAt,title` | `fields` | `JsonApiQueryParams::$sort` |
| Inclusion | `?include=author,comments` | `relationships` | `JsonApiQueryParams::$include` |
| Sparse fieldsets | `?fields[articles]=id,title` | `fields` | `JsonApiQueryParams::$fields` |
| Pagination | `?page[number]=2&page[size]=20` | page-size cap | `JsonApiQueryParams::$page` |

`JsonApiUrlParser::parse()` turns the request into a `JsonApiQueryParams`, which you hand to the query builder with `applyParams()`. A client may sort by any field in `fields` and include any relationship in `relationships` — there is no separate allow-list to maintain.

```php
$params = (new JsonApiUrlParser($config))->parse($request, Article::class);

$rows = (new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class, $registry))
    ->applyParams($params)
    ->operation('index')
    ->withTotalCount()
    ->get();
```

### Pagination defaults

The default page is `['number' => 1, 'size' => 10]` (see `JsonApiQueryParams`). The `JsonApiSerializer::parsePaginationParams()` helper caps page size at 100. If you use `JsonApiPaginator` directly, the default and maximum page size are configurable:

```php
use Modufolio\JsonApi\Pagination\JsonApiPaginator;

$paginator = new JsonApiPaginator();
$paginator->setDefaultPageSize(25);
$paginator->setMaxPageSize(100);
```

## Filters

Filters are objects implementing `FilterInterface`, registered per entity in a `FilterRegistry`. They are not part of the resource config.

The simplest setup registers the built-in `JsonApiFilterHandler` for every configured entity — it understands the standard JSON:API filter operators (`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`):

```php
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;

$registry = new FilterRegistry();
foreach (array_keys($config) as $entityClass) {
    $registry->register($entityClass, new JsonApiFilterHandler());
}
```

Or declare filters on the configurator and let it build the registry:

```php
use App\Entity\Article;
use Modufolio\JsonApi\Filter\DateFilter;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;

$api->filters(Article::class, [
    // Declare only the field-specific filters. buildFilterRegistry() adds the
    // catch-all and scopes it off these fields automatically.
    new SearchFilter([
        'title' => SearchStrategy::PARTIAL,   // LIKE %value%
        'slug'  => SearchStrategy::EXACT,      // = value
    ]),
    new DateFilter(['publishedAt']),
]);

$registry = $configurator->buildFilterRegistry();
```

> `buildFilterRegistry()` adds a catch-all `JsonApiFilterHandler` per entity, scoped off every field a declared filter already owns (here `title`, `slug`, and `publishedAt`), so the exact-match-vs-`SearchFilter` conflict can't occur. Just declare your field-specific filters — don't add an unscoped catch-all of your own, which would claim every field. See [Filtering › Composing filters](filtering.md#composing-filters--the-catch-all-and-field-specific-filters).

`SearchStrategy` cases: `PARTIAL` (`LIKE %v%`), `EXACT` (`= v`), `START` (`LIKE v%`), `END` (`LIKE %v`). See the [Filtering](filtering.md) guide for the full operator and strategy reference.

## Configuration examples

### Blog application

```php
use App\Entity\Article;
use App\Entity\Author;
use App\Entity\Comment;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    $api->resource(Article::class)
        ->key('articles')
        ->fields(['id', 'title', 'content', 'excerpt', 'publishedAt', 'slug', 'status'])
        ->relationships(['author', 'comments', 'tags', 'category'])
        ->operations([
            'index'  => true, 'show'   => true, 'create' => true,
            'update' => true, 'delete' => true,
        ]);

    // buildFilterRegistry() adds the catch-all, scoped off title/content for you.
    $api->filters(Article::class, [
        new SearchFilter(['title' => SearchStrategy::PARTIAL, 'content' => SearchStrategy::PARTIAL]),
    ]);

    $api->resource(Author::class)
        ->key('authors')
        ->fields(['id', 'name', 'email', 'bio', 'website'])
        ->relationships(['articles'])
        ->operations(['index' => true, 'show' => true]);

    $api->resource(Comment::class)
        ->key('comments')
        ->fields(['id', 'content', 'createdAt', 'status'])
        ->relationships(['article', 'author'])
        ->operations([
            'index'  => true, 'show'   => true, 'create' => true,
            'update' => true, 'delete' => true,
        ]);
};
```

### E-commerce application

```php
use App\Entity\Category;
use App\Entity\Product;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    $api->resource(Product::class)
        ->key('products')
        ->fields(['id', 'name', 'description', 'price', 'sku', 'stock', 'active'])
        ->relationships(['category', 'reviews', 'images', 'variants'])
        ->operations(['index' => true, 'show' => true]);

    // The auto catch-all handles price[gte], price[lte], active[eq], … on every
    // field except `name`, which the SearchFilter owns.
    $api->filters(Product::class, [
        new SearchFilter(['name' => SearchStrategy::PARTIAL]),
    ]);

    $api->resource(Category::class)
        ->key('categories')
        ->fields(['id', 'name', 'slug', 'description'])
        ->relationships(['products', 'parent', 'children'])
        ->operations(['index' => true, 'show' => true]);
};
```

## Performance notes

### Keep `fields` tight

```php
// Good: only what clients need — keeps the SELECT narrow
->fields(['id', 'title', 'publishedAt'])

// Avoid: pulling large text columns on every list request
->fields(['id', 'title', 'content', 'largeDescription'])
```

Clients can narrow further per request with sparse fieldsets (`?fields[articles]=id,title`), but the config `fields` list is the upper bound.

### Limit include depth

Nested includes (`comments.author`) translate to JOINs. Two levels is usually fine; avoid very deep chains like `comments.author.profile.preferences`.

## Next steps

- [Basic Usage](basic-usage.md) — using configured entities end to end
- [Query Builder](query-builder.md) — the query building API
- [Filtering](filtering.md) — full filter operator and strategy reference
- [API Reference](reference/index.md) — every public class and method
