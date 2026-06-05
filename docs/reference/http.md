# Reference: HTTP, pagination & utilities

## ResponseFactory

Namespace: `Modufolio\JsonApi\Http` · `readonly` class.

Wraps PSR-17 factories to produce JSON:API responses.

```php
public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
public function json(array|string $data, int $status = 200, array $headers = []): ResponseInterface
public function empty(int $status = 204): ResponseInterface
```

```php
$factory = new ResponseFactory($psr17ResponseFactory, $psr17StreamFactory);

return $factory->json($document->toArray(), 200, ['Content-Type' => 'application/vnd.api+json']);
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
