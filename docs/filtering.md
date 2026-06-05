# Filtering

Filtering is built from small objects that implement `FilterInterface`. You register one or more filters per resource class in a `FilterRegistry`, and the query builder applies them when a matching field appears in the request's `filter` parameters.

## The pieces

- **`FilterInterface`** — every filter implements `apply()`, `getDescription()`, and `supports()`.
- **`FilterRegistry`** — holds the filters for each resource class and applies them.
- **Built-in filters** — `JsonApiFilterHandler` (the standard operator set), `SearchFilter` (text strategies), `DateFilter` (date ranges).

There are exactly three built-in filters. There is no choice, numeric, boolean, conditional, or full-text filter — those behaviours are expressed with the operators below or with a custom filter.

## FilterInterface

```php
interface FilterInterface
{
    // Applies conditions to the DBAL query builder; returns the parameter bindings.
    public function apply(QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array;

    // Human-readable description of what this filter does.
    public function getDescription(): array;

    // Whether this filter handles the given field.
    public function supports(string $field): bool;
}
```

## JsonApiFilterHandler

The standard handler. It understands the JSON:API operator set and applies it to any field passed to it.

```php
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;

$handler = new JsonApiFilterHandler();

$handler->getSupportedOperators();
// ['eq', 'neq', 'not', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null', 'not_null']
```

| Operator | SQL |
|----------|-----|
| `eq` | `=` |
| `neq` / `not` | `!=` |
| `gt` / `gte` | `>` / `>=` |
| `lt` / `lte` | `<` / `<=` |
| `like` | `LIKE` |
| `in` | `IN (...)` |
| `null` | `IS NULL` |
| `not_null` | `IS NOT NULL` |

A bare value is treated as `eq`. The constructor optionally takes an allow-list of field names: `new JsonApiFilterHandler(['title', 'status'])`.

## SearchFilter

Text matching with a strategy per field. The constructor maps each field to a `SearchStrategy`:

```php
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;

$search = new SearchFilter([
    'name'    => SearchStrategy::PARTIAL,  // LIKE %value%
    'email'   => SearchStrategy::EXACT,    // = value
    'city'    => SearchStrategy::START,    // LIKE value%
    'country' => SearchStrategy::END,      // LIKE %value
]);
```

`SearchStrategy` is a string-backed enum, so plain strings (`'partial'`, `'exact'`, `'start'`, `'end'`) are accepted and normalized.

| Strategy | SQL |
|----------|-----|
| `PARTIAL` | `LIKE %value%` (contains) |
| `EXACT` | `= value` |
| `START` | `LIKE value%` (starts with) |
| `END` | `LIKE %value` (ends with) |

A `SearchFilter` applies when the request contains `filter[name]=...` for one of its configured fields.

## DateFilter

Range filtering on date/datetime fields. The constructor takes the list of fields it covers:

```php
use Modufolio\JsonApi\Filter\DateFilter;

$dates = new DateFilter(['publishedAt', 'createdAt']);
```

It recognises four operators — `after` (`>=`), `before` (`<=`), `strictly_after` (`>`), and `strictly_before` (`<`) — supplied as an operator map:

```php
// filter[publishedAt][after]=2024-01-01 & filter[publishedAt][before]=2024-12-31
['publishedAt' => ['after' => '2024-01-01', 'before' => '2024-12-31']]
```

These operators survive request parsing: `JsonApiUrlParser` whitelists `after`, `before`, `strictly_after`, and `strictly_before` alongside the standard set, so `?filter[publishedAt][after]=…` reaches `DateFilter` and filters end-to-end. The field must be in the resource's `fields` allow-list, and `DateFilter` must be registered for the resource (either directly or via `buildFilterRegistry()`, which scopes the field off the catch-all for you — see [Composing filters](#composing-filters--the-catch-all-and-field-specific-filters)).

## Registering filters

`FilterRegistry::register()` takes the **resource class first**, then the filter. Register as many filters per class as you need:

```php
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;

$registry = new FilterRegistry();
// Scope the catch-all off `title` (owned by the SearchFilter); see "Composing filters" below.
$registry->register(Article::class, new JsonApiFilterHandler(['status']));
$registry->register(Article::class, new SearchFilter(['title' => SearchStrategy::PARTIAL]));
```

Or declare the field-specific filters on the configurator and let it build the registry — it adds a correctly-scoped catch-all for you, so you don't list one yourself:

```php
$api->filters(Article::class, [
    new SearchFilter(['title' => SearchStrategy::PARTIAL]),
    new DateFilter(['publishedAt']),
]);

$registry = $configurator->buildFilterRegistry();
```

Pass the registry as the fifth argument to the query builder:

```php
$builder = new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class, $registry);
```

Filters then apply automatically whenever you run the query — `applyParams($params)` (from a request) or `filter([...])` (by hand).

## Composing filters — the catch-all and field-specific filters

Every registered filter that handles a field contributes its conditions, and they are combined with `AND`. This matters when a field is covered by **both** the catch-all `JsonApiFilterHandler` and a field-specific filter:

- A `JsonApiFilterHandler` constructed with no arguments handles **every** field. For `?filter[title]=foo` it emits `title = 'foo'` (exact).
- A `SearchFilter(['title' => SearchStrategy::PARTIAL])` emits `title LIKE '%foo%'`.

If both handle `title`, the query becomes `title = 'foo' AND title LIKE '%foo%'` — the exact match wins and partial search silently returns only exact hits.

**`buildFilterRegistry()` resolves this for you.** It inspects the filters you declared with `JsonApiConfigurator::filters()` and scopes the catch-all off every field a field-specific filter already owns (via each filter's `supports()`). So you just declare your `SearchFilter` / `DateFilter` and let the configurator add the catch-all — don't add a catch-all of your own:

```php
$api->filters(Article::class, [
    new SearchFilter(['title' => SearchStrategy::PARTIAL]),  // owns title
    new DateFilter(['publishedAt']),                          // owns publishedAt
]);

// Catch-all is added automatically, scoped to every field EXCEPT title and publishedAt.
$registry = $configurator->buildFilterRegistry();
```

If every field is owned by a field-specific filter, no catch-all is added at all (an empty allow-list would mean "all fields" and bring the conflict back). Note that an **unscoped** `JsonApiFilterHandler` you add to `filters()` yourself still conflicts — it claims every field — so leave the catch-all to `buildFilterRegistry()`, or scope it explicitly.

When you build a `FilterRegistry` **by hand**, the configurator isn't involved, so scope the catch-all yourself — list the fields it should handle, leaving the search/date fields out:

```php
$registry = new FilterRegistry();

// List the fields the catch-all owns — every field EXCEPT title and publishedAt.
$registry->register(Article::class, new JsonApiFilterHandler(['id', 'status']));
$registry->register(Article::class, new SearchFilter(['title' => SearchStrategy::PARTIAL]));
$registry->register(Article::class, new DateFilter(['publishedAt']));
```

A `DateFilter`-owned field is filtered with `after` / `before` (not `gte` / `lte`), since `DateFilter` owns it and the catch-all no longer sees it. If you'd rather filter a date field with `gte` / `lte`, leave it off the `DateFilter` and on the catch-all instead.

## Request filter formats

`JsonApiUrlParser` reads these shapes from the request and validates field names against the resource's `fields` allow-list:

```php
// Simple equality — ?filter[status]=published
['filter' => ['status' => 'published']]

// Operator map — ?filter[publishedAt][gte]=2023-01-01&filter[publishedAt][lt]=2024-01-01
['filter' => ['publishedAt' => ['gte' => '2023-01-01', 'lt' => '2024-01-01']]]

// IN — ?filter[status][in]=published,draft  (or repeated filter[status][]=)
['filter' => ['status' => ['published', 'draft']]]   // a list array is normalized to ['in' => [...]]

// Null check — ?filter[deletedAt][null]=true
['filter' => ['deletedAt' => ['null' => true]]]
```

Only the operators in `JsonApiFilterHandler::getSupportedOperators()` survive parsing; unknown operators and filters on fields outside the `fields` allow-list are dropped. Filtering on a related resource's column (e.g. `author.name`) is not supported through the request parser.

## Writing a custom filter

Implement `FilterInterface`. `apply()` receives the DBAL `QueryBuilder`, the request's filter params, the Doctrine field→column mappings, and the root table alias; it adds conditions and returns its parameter bindings.

```php
use Doctrine\DBAL\Query\QueryBuilder;
use Modufolio\JsonApi\Filter\FilterInterface;

final class ActiveProductFilter implements FilterInterface
{
    public function apply(QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array
    {
        if (($params['available'] ?? null) !== 'in_stock') {
            return [];
        }

        $qb->andWhere("$alias.stock > :min_stock")
           ->andWhere("$alias.status = :active_status");

        return ['min_stock' => 0, 'active_status' => 'active'];
    }

    public function getDescription(): array
    {
        return ['type' => 'ActiveProductFilter', 'description' => 'In-stock, active products'];
    }

    public function supports(string $field): bool
    {
        return $field === 'available';
    }
}
```

Register it like any other filter:

```php
$registry->register(Product::class, new ActiveProductFilter());
```

## Security

- **Parameterized always.** Filter values are bound as query parameters, never interpolated. A value like `'; DROP TABLE articles; --` becomes a harmless bound string.
- **Allow-listed fields.** A filter field must be in the resource's `fields` list; the parser drops anything else. Keeping `password` and similar out of `fields` makes them unfilterable.
- **Bounded operators.** Only the recognised operators are honoured; anything else is discarded during parsing.

## Next steps

- [Query Builder](query-builder.md) — how filters fit into query execution
- [Configuration](configuration.md) — registering filters via the configurator
- [API Reference](reference/index.md) — every public class and method
