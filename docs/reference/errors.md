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
| `MediaTypeUnsupported` | 415 | `MEDIA_TYPE_UNSUPPORTED` | `parameter: content-type` |
| `MediaTypeUnacceptable` | 406 | `MEDIA_TYPE_UNACCEPTABLE` | `parameter: accept` |
| `ResourceNotFound` | 404 | `RESOURCE_NOT_FOUND` | — |
| `QueryParamMalformed` | 400 | `QUERY_PARAM_MALFORMED` | the parameter, as the client wrote it |
| `InclusionUnrecognized` | 400 | `INCLUSION_UNRECOGNIZED` | `parameter: include` |
| `FieldUnrecognized` | 400 | `FIELD_UNRECOGNIZED` | `parameter: fields` |

Each also exposes the input that caused it — `getMediaType()`, `getFields()`,
`getIncludePath()`, `getQueryParam()`, `getId()` — so a handler can log or
re-render it without parsing the message.

## Which the library throws

`JsonApiQueryBuilder` raises `FieldUnrecognized` from its allow-list check and
`InclusionUnrecognized` for an `include` path that names an unknown
relationship, nests too deeply, or nests through a to-many. The remaining types
are vocabulary for your own controller: the builder does not perform content
negotiation, and reports a missing record as `['data' => null]` rather than
throwing, so the caller can decide between a 404 and a null relationship.

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
