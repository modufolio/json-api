# Reference: Input handling

Namespace: `Modufolio\JsonApi`

These classes turn request bodies (JSON:API or plain JSON) into a flat array you can apply to an entity. They do not touch the database — persistence stays in your controller.

## InputNormalizer

Accepts both `application/vnd.api+json` and `application/json` bodies and normalizes them.

```php
public function __construct()
public function normalize(array $payload, string $contentType, string $expectedResourceType): array
public function mergeData(array $normalizedData): array
public function isJsonApiFormat(array $payload): bool
public function detectContentType(string $contentTypeHeader): string
public function isSupported(string $contentType): bool
```

| Method | Purpose |
|--------|---------|
| `normalize($payload, $contentType, $type)` | Returns `['attributes' => [...], 'relationships' => [...]]`, validating the JSON:API `type` when the body is JSON:API. |
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
public function deserialize(array $payload, string $expectedType, bool $requireType = true): array
public function mergeData(array $attributes, array $relationships): array
```

`deserialize()` returns `['attributes' => [...], 'relationships' => [...]]`. When `$requireType` is true it validates the body's `type` against `$expectedType`.
