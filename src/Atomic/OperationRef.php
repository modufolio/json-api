<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Modufolio\JsonApi\LidRegistry;

/**
 * The `ref` of an atomic operation: which resource, or which relationship
 * of which resource, it acts on.
 *
 * Exactly one of `id` and `lid` is set. A `lid` names a resource created by
 * an earlier operation of the same request; {@see resolveId()} turns it into
 * the id that operation produced.
 */
final class OperationRef
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
        public readonly ?string $lid = null,
        public readonly ?string $relationship = null,
    ) {
    }

    public function targetsRelationship(): bool
    {
        return $this->relationship !== null;
    }

    /**
     * The id this ref points at, resolving a `lid` through the registry.
     */
    public function resolveId(LidRegistry $lids, string $pointer = ''): string
    {
        if ($this->id !== null) {
            return $this->id;
        }

        return $lids->resolve($this->type, (string) $this->lid);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $ref = ['type' => $this->type];
        if ($this->id !== null) {
            $ref['id'] = $this->id;
        }
        if ($this->lid !== null) {
            $ref['lid'] = $this->lid;
        }
        if ($this->relationship !== null) {
            $ref['relationship'] = $this->relationship;
        }

        return $ref;
    }
}
