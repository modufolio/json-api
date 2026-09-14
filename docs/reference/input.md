# Reference: Input handling

Namespace: `Modufolio\JsonApi`

These classes turn request bodies (JSON:API or plain JSON) into a flat array you can apply to an entity. They do not touch the database — persistence stays in your controller.

## InputNormalizer

Accepts both `application/vnd.api+json` and `application/json` bodies and normalizes them.

```php
public function __construct()
public function normalize(array $payload, string $contentType, string $expectedResourceType, ?LidRegistry $lids = null): array
public function mergeData(array $normalizedData): array
public function isJsonApiFormat(array $payload): bool
public function detectContentType(string $contentTypeHeader): string
public function isSupported(string $contentType): bool
```

| Method | Purpose |
|--------|---------|
| `normalize($payload, $contentType, $type, $lids)` | Returns `['attributes' => [...], 'relationships' => [...], 'id' => ?string, 'lid' => ?string]`. A JSON:API body whose `data.type` is missing or differs from `$type` throws `ResourceTypeConflict` (409). A relationship identifier carrying a `lid` is resolved through `$lids`, or throws `LidUnresolved` (400) without one. |
| `mergeData($normalized)` | Flattens the normalized structure into a single `[field => value]` array. |
| `isJsonApiFormat($payload)` | True if the payload has a JSON:API `data` envelope. |
| `detectContentType($header)` | Resolves a raw `Content-Type` header to a supported media type. |
| `isSupported($contentType)` | Whether the content type is handled. |

```php
$payload    = json_decode((string) $request->getBody(), true);
$normalizer = new InputNormalizer();
$normalized = $normalizer->normalize($payload, $request->getHeaderLine('Content-Type'), 'articles');
$data       = $normalizer->mergeData($normalized);   // ['title' => '...', 'author' => 5, ...]
```

## JsonApiRequestDeserializer

Lower-level deserializer for JSON:API bodies; used internally by `InputNormalizer`.

```php
public function deserialize(array $payload, string $expectedType, bool $requireType = true, ?LidRegistry $lids = null): array
public function mergeData(array $attributes, array $relationships): array
```

`deserialize()` returns `['attributes' => [...], 'relationships' => [...], 'id' => ?string, 'lid' => ?string]` — the primary resource's own `id` and JSON:API 1.1 `lid`, each `null` when absent. When `$requireType` is true it validates the body's `type` against `$expectedType`, throwing `ResourceTypeConflict` (409) when it is missing or different.

A relationship's resource identifier may carry `lid` instead of `id`. It is resolved to the id registered under that `type`/`lid` in `$lids`; with no registry, or an unknown pair, `LidUnresolved` (400) is thrown with a pointer to the identifier.

## LidRegistry

Namespace: `Modufolio\JsonApi` · `final`.

Maps the local identifiers of one request to the ids the server assigned, scoped by type. `OperationProcessor` fills it as atomic operations create resources; the deserializer reads it.

```php
public function register(string $type, string $lid, string $id): void      // LidConflict (400) for a different id under the same pair
public function has(string $type, string $lid): bool
public function resolve(string $type, string $lid): string                 // LidUnresolved (400)
public function resolveIdentifier(array $identifier, string $pointer = ''): string  // {type, id} or {type, lid}
public function all(): array                                               // ['type/lid' => id]
```

## AttributeCaster

Casts a raw request value to the PHP type Doctrine has mapped for an entity
field, so a normalized payload can be applied to an entity without each caller
re-implementing the conversions.

```php
public function __construct(EntityManagerInterface $em)
public function cast(object|string $entity, string $field, mixed $value): mixed
```

`$entity` is an instance or a class name; `$field` is the **entity property**
name, not the JSON:API attribute name. `null` passes through untouched, and a
value that cannot be cast raises `InvalidAttributeValueException`.
