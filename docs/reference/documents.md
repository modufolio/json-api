# Reference: Documents & serialization

Document objects live under `Modufolio\JsonApi\Document`; `JsonApiSerializer` is under `Modufolio\JsonApi`.

The document objects are fluent builders for a JSON:API response body. They implement `JsonSerializable`, so `json_encode()` works directly, and most expose `toArray()`.

## JsonApiDocument

The top-level response wrapper.

```php
public function __construct()
public function setData($data): self          // ResourceObject | ResourceObject[] | ResourceIdentifierObject | null
public function setErrors(array $errors): self // ErrorObject[]
public function setMeta(array $meta): self
public function setIncluded(array $included): self  // ResourceObject[]
public function setLinks(array $links): self
public function setJsonApi(array $jsonapi): self
public function jsonSerialize(): array
public function toArray(): array
```

```php
$document = new JsonApiDocument();
$document->setData($resource)->setMeta(['total' => 42]);
echo json_encode($document->toArray());
```

## ResourceObject

A single resource in the `data` / `included` section.

```php
public function __construct(string $type, ?string $id = null)
public function setLid(string $lid): self
public function setAttributes(array $attributes): self
public function setAttribute(string $name, $value): self
public function setRelationships(array $relationships): self
public function setToOneRelationship(string $name, ?ResourceIdentifierObject $related, array $links = []): self
public function setToManyRelationship(string $name, array $related, array $links = []): self
public function setLinks(array $links): self
public function setMeta(array $meta): self
public function jsonSerialize(): array
```

```php
$resource = (new ResourceObject('articles', '1'))
    ->setAttributes(['title' => 'Hello'])
    ->setToOneRelationship('author', new ResourceIdentifierObject('authors', '7'));
```

## ResourceIdentifierObject

A type/id pair used inside relationships.

```php
public function __construct(string $type, ?string $id = null)
public function setLid(string $lid): self
public function setMeta(array $meta): self
public function jsonSerialize(): array
```

## ErrorObject

One entry in the `errors` array.

```php
public function setId(string $id): self
public function setLinks(array $links): self
public function setStatus(int $status): self
public function setCode(string $code): self
public function setTitle(string $title): self
public function setDetail(string $detail): self
public function setSource(array $source): self
public function setMeta(array $meta): self
public function jsonSerialize(): array
```

```php
$error = (new ErrorObject())
    ->setStatus(422)
    ->setTitle('Validation Error')
    ->setDetail('title must not be blank')
    ->setSource(['pointer' => '/data/attributes/title']);

$document->setErrors([$error]);
```

## JsonApiSerializer

A collection of **static** helpers for building response arrays and parsing query parameters. There is no instance to construct and no `serialize()` method — call the static methods directly.

```php
public static function serializeResource(array $data, ?string $type = null, array $meta = [], array $included = []): array
public static function serializeCollection(array $data, int $total, int $currentPage = 1, int $perPage = 25, ?string $type = null, array $meta = [], array $included = [], ?string $baseUrl = null): array
public static function parsePaginationParams(array $queryParams): array   // ['number' => int, 'size' => int], size capped at 100
public static function parseFilterParams(array $queryParams): array
public static function parseSortParams(array $queryParams): array         // ['field' => 'ASC'|'DESC']
public static function parseIncludeParams(array $queryParams): array      // string[]
public static function serializeError(string $title, string $detail, int $status = 400, array $meta = []): array
public static function serializeValidationErrors(array $validationErrors): array  // [field => message]
```

```php
$page = JsonApiSerializer::parsePaginationParams($request->getQueryParams());
// ['number' => 1, 'size' => 25]
```
