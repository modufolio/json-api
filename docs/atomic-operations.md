# Atomic Operations

The [Atomic Operations extension](https://jsonapi.org/ext/atomic/) lets a
client send several creates, updates and removals in one request and have
the server apply them in order, in one transaction, all or nothing. A
resource created early in the request can be referred to later through a
local identifier (`lid`) before the server has assigned it an id — which is
what makes "create an author and an article by them" a single round trip.

This library implements the extension on top of JSON:API 1.1 content
negotiation. The pieces, in the order a request meets them:

| Step | Class |
|------|-------|
| Negotiate the media type, refuse what the server cannot do | `Http\MediaTypeNegotiator` |
| Validate the `atomic:operations` document | `Atomic\OperationsDocument` |
| Run the operations in a transaction, resolve `lid`s, point errors at operations | `Atomic\OperationProcessor` |
| Perform one operation | `Atomic\OperationHandler` (`Atomic\QueryBuilderOperationHandler` ships) |
| Build the `atomic:results` response | `Atomic\ResultsDocument` |
| Send it with the right `Content-Type` | `Http\ResponseFactory::jsonApi()` |

## The request

```http
POST /api/operations HTTP/1.1
Content-Type: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
Accept: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"

{
  "atomic:operations": [
    {
      "op": "add",
      "data": {
        "type": "authors",
        "lid": "new-author",
        "attributes": { "name": "Ada" }
      }
    },
    {
      "op": "add",
      "data": {
        "type": "articles",
        "attributes": { "title": "On engines" },
        "relationships": {
          "author": { "data": { "type": "authors", "lid": "new-author" } }
        }
      }
    },
    {
      "op": "remove",
      "ref": { "type": "articles", "id": "17" }
    }
  ]
}
```

Every operation has an `op` of `add`, `update` or `remove`. It targets a
resource through `ref` (`type` with `id` or `lid`, optionally a
`relationship`) or `href`, except an `add` of a new resource, whose `data`
says what to create. An `update` may also name its target through
`data.type` and `data.id`.

## The endpoint

```php
use Modufolio\JsonApi\Atomic\AtomicExtension;
use Modufolio\JsonApi\Atomic\OperationProcessor;
use Modufolio\JsonApi\Atomic\OperationsDocument;
use Modufolio\JsonApi\Atomic\QueryBuilderOperationHandler;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Exception\JsonApiExceptionInterface;
use Modufolio\JsonApi\Http\MediaTypeNegotiator;
use Modufolio\JsonApi\JsonApiQueryBuilder;

$negotiator = new MediaTypeNegotiator(supportedExtensions: [AtomicExtension::URI]);

try {
    // 415 for a foreign parameter or an unsupported extension, 406 when
    // the client cannot take what we produce.
    $requestType  = $negotiator->negotiateContentType($request->getHeaderLine('Content-Type'));
    $responseType = $negotiator->negotiateAccept($request->getHeaderLine('Accept'));

    $operations = OperationsDocument::parse(json_decode((string) $request->getBody(), true), maxOperations: 100);

    $handler = new QueryBuilderOperationHandler(
        $config,
        fn (string $entityClass) => (new JsonApiQueryBuilder($config, $em, $em->getConnection(), $entityClass, $registry))
            ->scope(['account' => $currentAccountId]),
    );

    $results = (new OperationProcessor($handler, $em->getConnection()))->process($operations);
} catch (JsonApiExceptionInterface $e) {
    $document = (new JsonApiDocument())->setErrors([$e->toErrorObject()]);

    return $responseFactory->jsonApi($document, $e->getStatus(), $responseType ?? null);
}

if ($results->isEmpty()) {
    return $responseFactory->empty(204);
}

return $responseFactory->jsonApi($results->toArray(), 200, $results->mediaType());
```

The response lists one result per operation, in order; a result with
nothing to say is `{}`:

```json
{
  "jsonapi": { "version": "1.1", "ext": ["https://jsonapi.org/ext/atomic"] },
  "atomic:results": [
    { "data": { "type": "authors", "id": "9", "attributes": { "name": "Ada" } } },
    { "data": { "type": "articles", "id": "31", "attributes": { "title": "On engines" },
                "relationships": { "author": { "data": { "type": "authors", "id": "9" } } } } },
    {}
  ]
}
```

The `Content-Type` of the response carries the extension too — the
specification requires the header and the `jsonapi` object to agree, and
`ResultsDocument::mediaType()` is the header that matches.

## What the shipped handler does

`QueryBuilderOperationHandler` runs each operation through the builder's own
`create`, `update` and `delete`, so the resource's field allow-list, its
`operations` flags and a required [scope](query-builder.md#scoping) apply to
an atomic request exactly as to a single one. The factory you pass is where
the scope goes.

| Operation | Support |
|-----------|---------|
| `add` a resource | Yes. The `lid` in `data` is registered from the created id |
| `update` a resource by `ref` or `data.id` | Yes |
| `remove` a resource | Yes |
| `update` a to-one relationship (`ref.relationship`, `data` an identifier or `null`) | Yes |
| `add`/`update`/`remove` on a to-many relationship | 403 — the builder writes a row, not a join table |
| `href` targets | 403 |

Write your own `OperationHandler` to run operations through Doctrine
entities or a service layer; the processor's guarantees — order,
transaction, `lid` resolution, error pointers — do not depend on the
handler.

## Errors

When any operation fails, nothing is committed and the response is an
`errors` document whose `source.pointer` names the operation:

```json
{
  "errors": [{
    "status": "404",
    "code": "RESOURCE_NOT_FOUND",
    "title": "The requested resource is not found",
    "detail": "No articles exists with id '17'.",
    "source": { "pointer": "/atomic:operations/2" }
  }]
}
```

A failure that already points into the operation's document is re-rooted:
a type conflict at `/data/type` becomes `/atomic:operations/1/data/type`.
A malformed document — an unknown `op`, a `ref` without `id` or `lid`, a
`remove` carrying `data`, more operations than the endpoint allows — is a
400 raised while parsing, before anything runs.

Local identifiers are checked up front too. Before the transaction opens,
`LidValidator` walks the list and refuses a `lid` referenced before the
`add` that declares it, one referenced inside that same `add`, and one
declared twice for a type — each with a pointer to the exact member
(`/atomic:operations/1/data/relationships/author/data`,
`/atomic:operations/0/ref/lid`). A request that cannot succeed costs no
database work.

| Class | Status | Code |
|-------|--------|------|
| `OperationMalformed` | 400 | `ATOMIC_OPERATION_MALFORMED` |
| `OperationUnsupported` | 403 | `ATOMIC_OPERATION_UNSUPPORTED` |
| `OperationFailed` | the wrapped failure's | the wrapped failure's |
| `LidUnresolved` | 400 | `LID_UNRESOLVED` |
| `LidConflict` | 400 | `LID_CONFLICT` |

## Local identifiers outside atomic requests

A `lid` is a JSON:API 1.1 feature, not an extension one. Outside an atomic
request it appears in a `POST` body's primary data, and
`JsonApiRequestDeserializer` returns it beside the attributes. A
relationship identifier carrying a `lid` needs a `LidRegistry` to resolve
against; without one the deserializer raises `LidUnresolved`, since a plain
`POST` creates one resource and there is nothing for the `lid` to refer to.
