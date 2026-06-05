# Reference: Configuration

Namespace: `Modufolio\JsonApi`

## JsonApiConfigurator

Builds the configuration array consumed by the rest of the library. Use either the fluent `resource()` API or the entity-based `addEntity()` / `entities()` methods; both produce the same array.

```php
public function entities(array $entities): self
public function addEntity(string $entityClass): self
public function getEntities(): array
public function filters(string $entityClass, array $filters): self
public function getFilters(): array
public function buildConfig(): array
public function buildFilterRegistry(): FilterRegistry
public function resource(string $entityClass): ResourceConfigurator
public function setResourceConfig(string $entityClass, array $config): void   // @internal, used by ResourceConfigurator
public function clearCache(): self
```

| Method | Notes |
|--------|-------|
| `entities(array)` | Register many entity classes at once (each must implement `JsonApiResource`). |
| `addEntity(string)` | Register one entity class; `buildConfig()` reads its static `getApi*()` methods. |
| `filters(string $entityClass, array $filters)` | Attach `FilterInterface` instances to an entity. |
| `buildConfig()` | Returns `array<class-string, array{resource_key, fields, relationships, operations}>`. |
| `buildFilterRegistry()` | Returns a `FilterRegistry` populated from `filters()`, plus a catch-all `JsonApiFilterHandler` per entity that is automatically scoped off every field a declared field-specific filter already owns (so its exact match can't clobber a `SearchFilter`/`DateFilter`). If every field is owned, no catch-all is added — see [Filtering › Composing filters](../filtering.md#composing-filters--the-catch-all-and-field-specific-filters). |
| `resource(string)` | Returns a `ResourceConfigurator` for the fluent config API. |

`buildConfig()` produces only four keys per entity: `resource_key`, `fields`, `relationships`, `operations`. No other keys are read anywhere in the library.

## ResourceConfigurator

Returned by `JsonApiConfigurator::resource()`. Each setter saves back to the parent configurator, so the calls chain.

```php
public function __construct(JsonApiConfigurator $configurator, string $entityClass)
public function key(string $key): self
public function fields(array $fields): self
public function relationships(array $relationships): self
public function operations(array $operations): self
```

| Method | Maps to config key | Shape |
|--------|--------------------|-------|
| `key(string)` | `resource_key` | the JSON:API `type` |
| `fields(array)` | `fields` | `string[]` |
| `relationships(array)` | `relationships` | `string[]` |
| `operations(array)` | `operations` | `array<string,bool>`, e.g. `['index' => true]` |

```php
$api->resource(Article::class)
    ->key('articles')
    ->fields(['id', 'title', 'content'])
    ->relationships(['author', 'comments'])
    ->operations(['index' => true, 'show' => true]);
```

## JsonApiResource (interface)

Implement this on a Doctrine entity to make it self-describing; register it with `JsonApiConfigurator::addEntity()`.

```php
interface JsonApiResource
{
    public static function getResourceKey(): string;      // → resource_key
    public static function getApiFields(): array;          // → fields  (array<int,string>)
    public static function getApiRelationships(): array;   // → relationships (array<int,string>)
    public static function getApiOperations(): array;      // → operations (array<string,bool>)
    public function getId(): mixed;                         // the JSON:API id
}
```
