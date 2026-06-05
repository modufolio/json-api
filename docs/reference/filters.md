# Reference: Filters

Namespace: `Modufolio\JsonApi\Filter`

## FilterInterface

```php
interface FilterInterface
{
    public function apply(QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array;
    public function getDescription(): array;
    public function supports(string $field): bool;
}
```

`apply()` receives the Doctrine DBAL `QueryBuilder`, the request's filter params, the field→column mappings, and the root table alias. It adds conditions and returns its parameter bindings (`['param' => value]`).

## FilterRegistry

Holds the filters for each resource class and applies them. **`register()` takes the resource class first.**

```php
public function register(string $resourceClass, FilterInterface $filter): void
public function getFilters(string $resourceClass): array            // FilterInterface[]
public function hasFilters(string $resourceClass): bool
public function applyFilters(string $resourceClass, QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array
public function getFilterDescriptions(string $resourceClass): array
```

```php
$registry = new FilterRegistry();
$registry->register(Article::class, new JsonApiFilterHandler());
$registry->register(Article::class, new SearchFilter(['title' => SearchStrategy::PARTIAL]));
```

## JsonApiFilterHandler

The standard operator handler.

```php
public function __construct(array $allowedFields = [])
public function apply(QueryBuilder $qb, array $filters, array $fieldMappings, string $alias = 't0'): array
public function getSupportedOperators(): array
public function supportsOperator(string $operator): bool
public function getDescription(): array
public function supports(string $field): bool
```

Operators: `eq`, `neq` / `not`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`, `not_null`. A bare scalar value is treated as `eq`.

## SearchFilter

Per-field text matching.

```php
public function __construct(array $properties = [])   // ['field' => SearchStrategy|string]
public function apply(QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array
public function getDescription(): array
public function supports(string $field): bool
```

```php
new SearchFilter([
    'title' => SearchStrategy::PARTIAL,
    'slug'  => SearchStrategy::EXACT,
]);
```

## SearchStrategy (enum)

String-backed enum.

```php
enum SearchStrategy: string
{
    case PARTIAL = 'partial';   // LIKE %value%
    case EXACT   = 'exact';     // = value
    case START   = 'start';     // LIKE value%
    case END     = 'end';       // LIKE %value
}
```

Plain strings (`'partial'`, etc.) are accepted by `SearchFilter` and normalized to the enum.

## DateFilter

Range filtering on date/datetime fields.

```php
public function __construct(array $properties = [])   // list of date field names
public function apply(QueryBuilder $qb, array $params, array $fieldMappings, string $alias = 't0'): array
public function getDescription(): array
public function supports(string $field): bool
```

Recognises four operators in the filter value: `after` (`>=`), `before` (`<=`), `strictly_after` (`>`), and `strictly_before` (`<`). They survive request parsing, so `?filter[publishedAt][after]=…` reaches the filter.

```php
new DateFilter(['publishedAt', 'createdAt']);
// value shape: ['publishedAt' => ['after' => '2024-01-01', 'before' => '2024-12-31']]
```

See the [Filtering guide](../filtering.md) for how these compose, request formats, and writing a custom filter.
