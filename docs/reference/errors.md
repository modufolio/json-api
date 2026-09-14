# Errors

`Modufolio\JsonApi\Exception`

A request can fail in ways that mean very different things to a client: a body
in an unreadable media type is a 415, an unknown `include` path is a 400, an
absent record is a 404. A bare `InvalidArgumentException` carries none of that,
so a controller catching one has to guess a status or return 500. These types
carry it.

Every one of them extends `InvalidArgumentException`, so code written before
they existed keeps catching them. Catch `JsonApiExceptionInterface` when you
want the status and source instead of the message.

## `JsonApiExceptionInterface`

| Method | Returns |
|--------|---------|
| `getStatus(): int` | The HTTP status this failure warrants |
| `getErrorCode(): string` | Stable machine-readable code — branch on this, not on the message |
| `getSource(): array<string, string>` | The JSON:API `source` member, or `[]` when the failure cannot be attributed to one part of the request |
| `toErrorObject(): ErrorObject` | Ready for a document's `errors` member |

The message (`getMessage()`) is the human-readable `detail`. It is free to change
between releases; the code is not.

## The types

| Class | Status | Code | Source |
|-------|--------|------|--------|
| `MediaTypeUnsupported` | 415 | `MEDIA_TYPE_UNSUPPORTED` | `header: Content-Type` |
| `MediaTypeUnacceptable` | 406 | `MEDIA_TYPE_UNACCEPTABLE` | `header: Accept` |
| `ResourceNotFound` | 404 | `RESOURCE_NOT_FOUND` | — |
| `QueryParamMalformed` | 400 | `QUERY_PARAM_MALFORMED` | the parameter, as the client wrote it |
| `InclusionUnrecognized` | 400 | `INCLUSION_UNRECOGNIZED` | `parameter: include` |
| `FieldUnrecognized` | 400 | `FIELD_UNRECOGNIZED` | `parameter: fields` |
| `ResourceTypeConflict` | 409 | `RESOURCE_TYPE_CONFLICT` | `pointer: /data/type` |
| `LidUnresolved` | 400 | `LID_UNRESOLVED` | the identifier's pointer, when known |
| `LidConflict` | 400 | `LID_CONFLICT` | the identifier's pointer, when known |
| `OperationMalformed` | 400 | `ATOMIC_OPERATION_MALFORMED` | `pointer` into `atomic:operations` |
| `OperationUnsupported` | 403 | `ATOMIC_OPERATION_UNSUPPORTED` | `pointer` into `atomic:operations` |
| `OperationFailed` | the wrapped failure's | the wrapped failure's | the wrapped failure's pointer, re-rooted at `/atomic:operations/{i}` |

The two media type failures name the header at fault through the JSON:API
1.1 `source.header` member (before 1.1 they used `parameter`, which the
specification reserves for query parameters).

Each also exposes the input that caused it — `getMediaType()`, `getFields()`,
`getIncludePath()`, `getQueryParam()`, `getId()`, `getExpectedType()` and
`getActualType()` — so a handler can log or re-render it without parsing the
message.

## Which the library throws

`JsonApiQueryBuilder` raises `FieldUnrecognized` from its allow-list check and
`InclusionUnrecognized` for an `include` path that names an unknown
relationship, nests too deeply, or nests through a to-many. `JsonApiUrlParser`
raises `QueryParamMalformed` for a `fields`, `include` or `sort` value that is
not a comma-separated string, and — when constructed with
`rejectUnknownQueryParams: true` — for any query parameter the endpoint does
not process. `JsonApiRequestDeserializer` — and so
`InputNormalizer` — raises `ResourceTypeConflict` when a JSON:API body carries
no `type`, or one other than the endpoint's; the specification requires a 409
there rather than a 400. It raises `LidUnresolved` for a relationship
identifier whose `lid` no `LidRegistry` knows. `MediaTypeNegotiator` raises
the two media type failures. The atomic operations classes raise
`OperationMalformed` while parsing, `OperationUnsupported` from the shipped
handler, and `OperationFailed` from the processor around any failure a
handler throws. `ResourceNotFound` is vocabulary for your own controller:
the builder reports a missing record as `['data' => null]` rather than
throwing, so the caller can decide between a 404 and a null relationship
(the atomic handler does throw it).

## Handling them

```php
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Exception\JsonApiExceptionInterface;

try {
    $result = $builder->applyParams($params)->operation('index')->get();
} catch (JsonApiExceptionInterface $e) {
    $document = new JsonApiDocument();
    $document->setErrors([$e->toErrorObject()]);

    return $this->jsonApiResponse($document, $e->getStatus());
}
```

A client then sees which parameter it got wrong:

```json
{
  "errors": [
    {
      "status": "400",
      "code": "INCLUSION_UNRECOGNIZED",
      "title": "The requested inclusion is unrecognized",
      "detail": "Unknown include: publisher in path publisher",
      "source": { "parameter": "include" }
    }
  ]
}
```
