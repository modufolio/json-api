# Reference: Atomic operations

Namespace: `Modufolio\JsonApi\Atomic` (exceptions under `Modufolio\JsonApi\Exception`).

The server side of the [Atomic Operations extension](https://jsonapi.org/ext/atomic/).
The narrative is in the [Atomic Operations](../atomic-operations.md) guide.

## AtomicExtension

Constants only.

```php
public const URI = 'https://jsonapi.org/ext/atomic';
public const NAMESPACE = 'atomic';
public const OPERATIONS = 'atomic:operations';
public const RESULTS = 'atomic:results';
```

## OperationsDocument

Parses and validates a request body. Every structural fault is an
`OperationMalformed` (400) with a pointer to the member.

```php
public static function parse(array $payload, ?int $maxOperations = null): array   // list<Operation>
```

Rules enforced: `atomic:operations` is a non-empty list, not beside `data`,
`errors`, `included` or `atomic:results`; at most `$maxOperations` entries
when given; each entry is an object with `op` in `add`/`update`/`remove`;
`ref` and `href` are exclusive; a `ref` has `type` and exactly one of
`id`/`lid`, and nothing but `type`, `id`, `lid`, `relationship`; an `add` has
a `ref` with a `relationship` or a resource object with a `type` in `data`;
an `update`/`remove` has a target (`ref`, `href`, or for `update`
`data.type`+`data.id`); a relationship operation has `data`; a resource
`remove` has none.

## Operation

```php
public const ADD = 'add'; public const UPDATE = 'update'; public const REMOVE = 'remove';

public readonly int $index;             // position in atomic:operations
public readonly string $op;
public readonly ?OperationRef $ref;
public readonly ?string $href;
public readonly mixed $data;            // decoded JSON as sent
public readonly bool $hasData;          // data was present, even if null
public readonly array $meta;

public function pointer(string $suffix = ''): string   // '/atomic:operations/{index}' . $suffix
public function targetsRelationship(): bool
public function type(): ?string                        // ref type, or data.type
public function dataLid(): ?string                     // data.lid
```

## OperationRef

```php
public readonly string $type;
public readonly ?string $id;
public readonly ?string $lid;
public readonly ?string $relationship;

public function targetsRelationship(): bool
public function resolveId(LidRegistry $lids, string $pointer = ''): string   // id, or the lid's registered id
public function toArray(): array
```

## OperationHandler

The one interface to implement for your own persistence.

```php
public function handle(Operation $operation, LidRegistry $lids): OperationResult;
```

Throw a `JsonApiExceptionInterface` to fail the request with a status; the
processor wraps it in `OperationFailed` and points it at the operation.

## QueryBuilderOperationHandler

`OperationHandler` over `JsonApiQueryBuilder`.

```php
public function __construct(array $config, callable $builderFactory, ?JsonApiRequestDeserializer $deserializer = null)
// $builderFactory: fn (class-string $entityClass): JsonApiQueryBuilder
```

Supports resource `add`/`update`/`remove` and to-one relationship `update`;
declines to-many relationship operations and `href` with
`OperationUnsupported` (403). Resource types resolve to entity classes
through the configuration's `resource_key`.

## OperationProcessor

```php
public function __construct(OperationHandler $handler, Doctrine\DBAL\Connection $connection)
public function process(array $operations, ?LidRegistry $lids = null): ResultsDocument
```

Runs `LidValidator` first, then every operation in order inside
`Connection::transactional()`. Registers the `lid` of each `add` from the
result's id. A `JsonApiExceptionInterface` from the handler becomes
`OperationFailed`; any other throwable propagates after the rollback.

## LidValidator

```php
public function validate(array $operations): void   // list<Operation>
```

Checks, before anything runs, that every `lid` is declared by an `add`
before it is referenced (in a `ref`, a relationship, or a relationship
operation's data), that none is referenced inside the operation declaring
it, and that no type declares the same `lid` twice. Throws `LidUnresolved`
or `LidConflict` (both 400) with the pointer of the offending member.

## OperationResult

```php
public static function none(array $meta = []): self                       // {}
public static function of(ResourceObject|array|null $data, array $meta = []): self
public function isEmpty(): bool
public function resourceId(): ?string
```

## ResultsDocument

```php
public function add(OperationResult $result): self
public function results(): array
public function setMeta(array $meta): self
public function setProfiles(array $profiles): self
public function isEmpty(): bool           // true → answer 204
public function mediaType(): MediaType    // the Content-Type to send it with
public function toArray(): array          // jsonapi {version, ext[, profile]}, atomic:results[, meta]
```
