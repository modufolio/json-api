<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

/**
 * One entry of an `atomic:operations` array, validated and typed.
 *
 * `op` is one of the three the extension defines. The target is either a
 * {@see OperationRef} or an `href`; an `add` of a new resource has neither,
 * its `data` saying what to create. `data` is kept as decoded JSON — a
 * resource object, an identifier, a list of identifiers or null — because
 * what it means depends on the operation, and the handler is the one that
 * knows.
 */
final class Operation
{
    public const ADD = 'add';
    public const UPDATE = 'update';
    public const REMOVE = 'remove';

    public const OPS = [self::ADD, self::UPDATE, self::REMOVE];

    /**
     * @param int                  $index   Position in the request's `atomic:operations` array
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly int $index,
        public readonly string $op,
        public readonly ?OperationRef $ref = null,
        public readonly ?string $href = null,
        public readonly mixed $data = null,
        public readonly bool $hasData = false,
        public readonly array $meta = [],
    ) {
    }

    /**
     * JSON Pointer to this operation in the request document, the prefix of
     * every error `source.pointer` about it.
     */
    public function pointer(string $suffix = ''): string
    {
        return '/' . AtomicExtension::OPERATIONS . '/' . $this->index . $suffix;
    }

    public function targetsRelationship(): bool
    {
        return $this->ref?->targetsRelationship() ?? false;
    }

    /**
     * The resource type this operation is about: the ref's, or the type of
     * the resource object in `data` for an `add` that creates one.
     */
    public function type(): ?string
    {
        if ($this->ref !== null) {
            return $this->ref->type;
        }

        if (is_array($this->data) && isset($this->data['type']) && is_string($this->data['type'])) {
            return $this->data['type'];
        }

        return null;
    }

    /**
     * The `lid` the resource object in `data` carries, if any.
     */
    public function dataLid(): ?string
    {
        if (is_array($this->data) && isset($this->data['lid']) && is_scalar($this->data['lid'])) {
            return (string) $this->data['lid'];
        }

        return null;
    }
}
