<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Closure;
use InvalidArgumentException;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\Exception\JsonApiExceptionInterface;
use Modufolio\JsonApi\Exception\OperationMalformed;
use Modufolio\JsonApi\Exception\OperationUnsupported;
use Modufolio\JsonApi\Exception\ResourceNotFound;
use Modufolio\JsonApi\Helpers\Str;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiRequestDeserializer;
use Modufolio\JsonApi\LidRegistry;

/**
 * Runs atomic operations through {@see JsonApiQueryBuilder}.
 *
 * Resources are created, updated and removed with the builder's own
 * `create`, `update` and `delete` operations, so everything the builder
 * enforces — the field allow-list, the operation flags, a required scope —
 * applies to an atomic request exactly as to a single one. The factory you
 * pass is where that scope is set:
 *
 *     new QueryBuilderOperationHandler(
 *         $config,
 *         fn (string $entityClass) => (new JsonApiQueryBuilder($config, $em, $conn, $entityClass, $registry))
 *             ->scope(['account' => $currentAccountId]),
 *     );
 *
 * Supported: `add`, `update` and `remove` of a resource identified by `ref`
 * (with `id` or `lid`) or by `data.type`/`data.id`; `update` of a to-one
 * relationship. To-many relationship operations and `href` targets are
 * declined with a 403, because the builder writes a row, not a join table.
 * Write your own {@see OperationHandler} for those.
 *
 * Resource type names in `data` and `ref` are resolved to entity classes
 * through the configuration's `resource_key`; the deserializer's checks
 * (type conflict, identifier shape, `lid` resolution) run on every `data`.
 */
final class QueryBuilderOperationHandler implements OperationHandler
{
    /** @var Closure(class-string): JsonApiQueryBuilder */
    private readonly Closure $builderFactory;
    private readonly JsonApiRequestDeserializer $deserializer;

    /**
     * @param array<string, mixed>                      $config         The configurator's output
     * @param callable(class-string): JsonApiQueryBuilder $builderFactory A fresh builder for an entity class
     */
    public function __construct(
        private readonly array $config,
        callable $builderFactory,
        ?JsonApiRequestDeserializer $deserializer = null,
    ) {
        $this->builderFactory = $builderFactory(...);
        $this->deserializer = $deserializer ?? new JsonApiRequestDeserializer();
    }

    public function handle(Operation $operation, LidRegistry $lids): OperationResult
    {
        if ($operation->href !== null) {
            throw new OperationUnsupported(
                'This endpoint identifies operation targets by ref, not href.',
                $operation->pointer('/href'),
            );
        }

        $type = $operation->type();
        if ($type === null) {
            throw new OperationMalformed('The operation names no resource type.', $operation->pointer());
        }
        $entityClass = $this->entityClassFor($type, $operation);

        try {
            if ($operation->targetsRelationship()) {
                return $this->updateRelationship($operation, $entityClass, $lids);
            }

            return match ($operation->op) {
                Operation::ADD => $this->add($operation, $type, $entityClass, $lids),
                Operation::UPDATE => $this->update($operation, $type, $entityClass, $lids),
                Operation::REMOVE => $this->remove($operation, $type, $entityClass, $lids),
                default => throw new OperationMalformed("Unknown op '{$operation->op}'.", $operation->pointer('/op')),
            };
        } catch (JsonApiExceptionInterface $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            // The deserializer and the builder report a client's mistake as a
            // bare InvalidArgumentException; inside an atomic request it is a
            // malformed operation, and the pointer says which.
            throw new OperationMalformed($e->getMessage(), $operation->pointer('/data'));
        }
    }

    /**
     * @param class-string $entityClass
     */
    private function add(Operation $operation, string $type, string $entityClass, LidRegistry $lids): OperationResult
    {
        $data = $this->deserialize($operation, $type, $lids);

        $result = ($this->builderFactory)($entityClass)
            ->withData($data)
            ->operation('create')
            ->get();

        return OperationResult::of($this->resourceFrom($type, $result));
    }

    /**
     * @param class-string $entityClass
     */
    private function update(Operation $operation, string $type, string $entityClass, LidRegistry $lids): OperationResult
    {
        $id = $this->targetId($operation, $lids);
        $data = $this->deserialize($operation, $type, $lids);

        $result = ($this->builderFactory)($entityClass)
            ->withId($id)
            ->withData($data)
            ->operation('update')
            ->get();

        if (($result['data'] ?? null) === null) {
            throw new ResourceNotFound($type, $id);
        }

        return OperationResult::of($this->resourceFrom($type, $result));
    }

    /**
     * @param class-string $entityClass
     */
    private function remove(Operation $operation, string $type, string $entityClass, LidRegistry $lids): OperationResult
    {
        $id = $this->targetId($operation, $lids);

        $result = ($this->builderFactory)($entityClass)
            ->withId($id)
            ->operation('delete')
            ->get();

        if (array_key_exists('data', $result) && $result['data'] === null) {
            throw new ResourceNotFound($type, $id);
        }

        return OperationResult::none();
    }

    /**
     * `update` of a to-one relationship: `data` is an identifier or null.
     *
     * @param class-string $entityClass
     */
    private function updateRelationship(Operation $operation, string $entityClass, LidRegistry $lids): OperationResult
    {
        /** @var OperationRef $ref */
        $ref = $operation->ref;
        $relationship = (string) $ref->relationship;

        if ($operation->op !== Operation::UPDATE || is_array($operation->data) && array_is_list($operation->data)) {
            throw new OperationUnsupported(
                "This endpoint updates to-one relationships only; '{$operation->op}' on '$relationship' with a list is a to-many operation.",
                $operation->pointer(),
            );
        }

        $related = null;
        if ($operation->data !== null) {
            if (!is_array($operation->data) || !isset($operation->data['type'])) {
                throw new OperationMalformed(
                    'A to-one relationship update takes a resource identifier object or null.',
                    $operation->pointer('/data'),
                );
            }
            $related = $lids->resolveIdentifier($operation->data, $operation->pointer('/data'));
        }

        $id = $ref->resolveId($lids);

        $result = ($this->builderFactory)($entityClass)
            ->withId($id)
            ->withData([$relationship => $related])
            ->operation('update')
            ->get();

        if (($result['data'] ?? null) === null) {
            throw new ResourceNotFound($ref->type, $id);
        }

        return OperationResult::none();
    }

    /**
     * The attributes and relationships of `data`, keyed by entity field.
     *
     * @return array<string, mixed>
     */
    private function deserialize(Operation $operation, string $type, LidRegistry $lids): array
    {
        if (!is_array($operation->data) || array_is_list($operation->data)) {
            throw new OperationMalformed('The operation must carry a resource object in data.', $operation->pointer('/data'));
        }

        $normalized = $this->deserializer->deserialize(
            payload: ['data' => $operation->data],
            expectedType: $type,
            requireType: true,
            lids: $lids,
        );

        $fields = [];
        foreach ($normalized['attributes'] as $attribute => $value) {
            $fields[Str::camel((string) $attribute)] = $value;
        }
        foreach ($normalized['relationships'] as $relationship => $value) {
            $fields[Str::camel((string) $relationship)] = $value;
        }

        return $fields;
    }

    /**
     * The id an update or remove targets: the ref's, or `data.id`.
     */
    private function targetId(Operation $operation, LidRegistry $lids): string
    {
        if ($operation->ref !== null) {
            return $operation->ref->resolveId($lids, $operation->pointer('/ref'));
        }

        $id = is_array($operation->data) ? ($operation->data['id'] ?? null) : null;
        if ($id === null || !is_scalar($id)) {
            throw new OperationMalformed('The operation names no target id.', $operation->pointer());
        }

        return (string) $id;
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type, Operation $operation): string
    {
        foreach ($this->config as $entityClass => $entityConfig) {
            if (($entityConfig['resource_key'] ?? null) === $type) {
                /** @var class-string $entityClass */
                return $entityClass;
            }
        }

        throw new OperationUnsupported(
            "No resource of type '$type' is served by this endpoint.",
            $operation->pointer($operation->ref !== null ? '/ref/type' : '/data/type'),
        );
    }

    /**
     * @param array<string, mixed> $result The builder's `get()` result for a single resource
     */
    private function resourceFrom(string $type, array $result): ResourceObject
    {
        /** @var array{id: int|string, attributes?: array<string, mixed>, relationships?: array<string, mixed>} $row */
        $row = $result['data'];

        return (new ResourceObject($type, (string) $row['id']))
            ->setAttributes($row['attributes'] ?? [])
            ->setRelationships($row['relationships'] ?? []);
    }
}
