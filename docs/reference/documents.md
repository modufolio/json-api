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
public function setLinks(array $links): self       // values: string | LinkObject | array | null
public function setJsonApi(array $jsonapi): self   // replaces the whole jsonapi object
public function setExtensions(array $extensions): self   // jsonapi.ext
public function setProfiles(array $profiles): self       // jsonapi.profile
public function setJsonApiMeta(array $meta): self        // jsonapi.meta
public function setMediaType(MediaType $mediaType): self // ext + profile from a negotiated type
public function getJsonApi(): array
public function jsonSerialize(): array
public function toArray(): array
```

Every document starts with `jsonapi: {version: "1.1"}` (`JsonApiDocument::VERSION`).
JSON:API 1.1 adds `ext`, `profile` and `meta` to that object; a document
that uses an extension's members must list the extension in `ext`, and the
response's `Content-Type` must carry the same list — `setMediaType()` and
`ResponseFactory::jsonApi()` take the same `MediaType` so the two agree.
The top-level `links` may carry `describedby` beside `self`, `related` and
the pagination links.

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
public function getType(): string
public function getId(): ?string
public function getLid(): ?string
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

## LinkObject

A JSON:API 1.1 link object, for any place a links array takes a value.
`href` is required; the rest is emitted only when set.

```php
public function __construct(string $href)
public function setRel(string $rel): self                          // RFC 8288 relation type
public function setDescribedBy(string|LinkObject $describedBy): self // link to a schema / description document
public function setTitle(string $title): self
public function setType(string $type): self                        // media type of the target
public function setHreflang(string|array $hreflang): self          // one RFC 5646 tag, or a list
public function setMeta(array $meta): self
public function getHref(): string
public function jsonSerialize(): array
public function toArray(): array
```

```php
$document->setLinks([
    'self'        => 'https://api.example.com/articles',
    'describedby' => (new LinkObject('https://api.example.com/openapi.json'))
        ->setType('application/vnd.oai.openapi+json'),
]);
```

## ErrorObject

One entry in the `errors` array.

```php
public function setId(string $id): self
public function setLinks(array $links): self          // about, and (1.1) type
public function setStatus(int $status): self
public function setCode(string $code): self
public function setTitle(string $title): self
public function setDetail(string $detail): self
public function setSource(array $source): self
public function setSourcePointer(string $pointer): self      // source: {pointer}
public function setSourceParameter(string $parameter): self  // source: {parameter}
public function setSourceHeader(string $header): self        // source: {header}  (1.1)
public function setMeta(array $meta): self
public function jsonSerialize(): array
```

The specification names three `source` members and says an error should
carry one of them or none: `pointer` into the request document, `parameter`
for a query parameter, and — new in 1.1 — `header` for a request header.

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

This helper defaults to size 25 — a third default, alongside `JsonApiQueryParams`' 10 and the query builder's own 25. It also accepts the legacy scalar `page` / `per_page` parameters in addition to `page[number]` / `page[size]`, and caps the size at 100.
