# Reference: HTTP, pagination & utilities

## MediaType

Namespace: `Modufolio\JsonApi\Http` · `final`, immutable.

One media type as it appears in a `Content-Type` or `Accept` header, with the
JSON:API 1.1 `ext` and `profile` parameters parsed into URI lists and kept
apart from every other parameter.

```php
public const JSON_API = 'application/vnd.api+json';

public readonly string $type;          // lower-cased type/subtype
public readonly array  $extensions;    // list<string> from ext
public readonly array  $profiles;      // list<string> from profile
public readonly array  $parameters;    // every other parameter, q excluded
public readonly float  $quality;       // q, 1.0 when absent

public static function jsonApi(array $extensions = [], array $profiles = []): self
public static function parse(string $value): self          // one media type; InvalidArgumentException if not one
public static function parseList(string $header): array    // an Accept header, most preferred first
public function isJsonApi(): bool
public function matchesJsonApi(): bool                     // the type itself, */* or application/*
public function hasOnlyJsonApiParameters(): bool           // nothing but ext, profile, q
public function withExtensions(array $extensions): self
public function withProfiles(array $profiles): self
public function toString(): string                         // ext and profile quoted, q dropped
```

```php
$type = MediaType::parse('application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"');
$type->extensions;   // ['https://jsonapi.org/ext/atomic']
(string) $type;      // 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"'
```

## MediaTypeNegotiator

Namespace: `Modufolio\JsonApi\Http` · `final`.

Applies the JSON:API 1.1 content negotiation rules. Both methods return the
`MediaType` the response must be sent as — the extensions and profiles both
sides agreed on — for `ResponseFactory::jsonApi()` and
`JsonApiDocument::setMediaType()`.

```php
public function __construct(array $supportedExtensions = [], array $supportedProfiles = [], array $otherContentTypes = [])
public function negotiateContentType(string $header): MediaType   // throws MediaTypeUnsupported (415)
public function negotiateAccept(string $header): MediaType        // throws MediaTypeUnacceptable (406)
public function supportedExtensions(): array
public function supportedProfiles(): array
```

| Situation | `negotiateContentType` | `negotiateAccept` |
|-----------|------------------------|-------------------|
| JSON:API type with a parameter other than `ext`/`profile` (`charset` included) | 415 | that instance is ignored |
| `ext` naming an extension not in `$supportedExtensions` | 415 | that instance is ignored |
| `profile` naming an unsupported profile | dropped from the result | dropped from the result |
| A type listed in `$otherContentTypes` (`application/json`) | returned as-is, any parameters | — |
| Header absent | 415 | plain JSON:API |
| `*/*` or `application/*` | — | plain JSON:API |
| No usable JSON:API instance left | — | 406 |

```php
$negotiator = new MediaTypeNegotiator(
    supportedExtensions: [AtomicExtension::URI],
    otherContentTypes: ['application/json'],
);

$requestType  = $negotiator->negotiateContentType($request->getHeaderLine('Content-Type'));
$responseType = $negotiator->negotiateAccept($request->getHeaderLine('Accept'));
```

## ResponseFactory

Namespace: `Modufolio\JsonApi\Http` · `readonly` class.

Wraps PSR-17 factories to produce JSON:API responses.

```php
public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
public function jsonApi(JsonApiDocument|array|string $document, int $status = 200, ?MediaType $mediaType = null, array $headers = []): ResponseInterface
public function json(array|string $data, int $status = 200, array $headers = []): ResponseInterface
public function empty(int $status = 204): ResponseInterface
```

`jsonApi()` sets `Content-Type` to the JSON:API media type, modified by the
`ext` and `profile` parameters of `$mediaType` when one is given — the
specification requires a server to announce the extensions and profiles it
applied. Pass the value the negotiator returned, and the same one to
`JsonApiDocument::setMediaType()`. It also adds `Vary: Accept`, since the
representation depends on that header. `json()` defaults to `application/json`.

```php
$factory = new ResponseFactory($psr17ResponseFactory, $psr17StreamFactory);

$document->setMediaType($responseType);
return $factory->jsonApi($document, 200, $responseType);
// or, for a DELETE:
return $factory->empty(204);
```

## JsonApiPaginator

Namespace: `Modufolio\JsonApi\Pagination`.

Applies pagination to a DBAL query and computes metadata. The query builder handles pagination for you via `page()`; use this directly only when paginating a query you built yourself.

```php
public function paginate(QueryBuilder $qb, int $page = 1, int $size = 25): QueryBuilder
public function getTotalCount(QueryBuilder $qb): int
public function getMetadata(int $total, int $page, int $size): array
public function getPageInfo(int $total, int $page, int $size): array
public function setDefaultPageSize(int $size): self
public function setMaxPageSize(int $size): self
public function getDefaultPageSize(): int
public function getMaxPageSize(): int
```

## Str

Namespace: `Modufolio\JsonApi\Helpers` · all methods **static**.

Case conversion helpers used internally for mapping between JSON:API (snake_case) and PHP (camelCase) names.

```php
public static function camel(string $value): string                          // 'published_at' → 'publishedAt'
public static function studly(string $value): string                         // 'published_at' → 'PublishedAt'
public static function snake(string $value, string $delimiter = '_'): string // 'publishedAt'  → 'published_at'
public static function lower(string $value): string
```

## SafeExpressionBuilder

Namespace: `Modufolio\JsonApi`.

Wraps Doctrine's `ExpressionBuilder` with validation (SQL-identifier checks, `IN` value-count limits, `LIKE` pattern validation) so expressions can be composed safely.

```php
public function __construct(ExpressionBuilder $expr)
public function eq(string $field, string $value): string
public function neq(string $field, string $value): string
public function gt(string $field, string $value): string
public function gte(string $field, string $value): string
public function lt(string $field, string $value): string
public function lte(string $field, string $value): string
public function like(string $field, string $value): string
public function in(string $field, array $values): string
public function notIn(string $field, array $values): string
public function isNull(string $field): string
public function isNotNull(string $field): string
public function and(string ...$expressions): string
public function or(string ...$expressions): string
public function literal(string $value): string
public function getUnsafeExpressionBuilder(): ExpressionBuilder
```
