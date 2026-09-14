<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Modufolio\JsonApi\Exception\LidConflict;
use Modufolio\JsonApi\Exception\LidUnresolved;

/**
 * Checks every `lid` in an operations list before any operation runs.
 *
 * A local identifier is declared by the `add` whose `data` carries it and
 * may be referenced by any *later* operation: in a `ref`, in a relationship
 * of a resource object, or as the identifier(s) of a relationship
 * operation. Walking the list once with those rules catches a duplicate
 * declaration, a reference to a `lid` nothing declares, and a reference
 * inside the very operation that declares it — all before a transaction is
 * opened, so a malformed request costs no database work and the error
 * points at the exact member.
 *
 * The processor runs this itself; it is public so a custom pipeline can
 * run it too.
 */
final class LidValidator
{
    /**
     * @param list<Operation> $operations
     *
     * @throws LidConflict   a `lid` declared twice for one type
     * @throws LidUnresolved a `lid` referenced before, or without, its declaration
     */
    public function validate(array $operations): void
    {
        /** @var array<string, true> $declared keyed `type/lid` */
        $declared = [];

        foreach ($operations as $operation) {
            $this->assertReferencesResolve($operation, $declared);

            if ($operation->op === Operation::ADD && !$operation->targetsRelationship()) {
                $lid = $operation->dataLid();
                $type = $operation->type();
                if ($lid !== null && $type !== null) {
                    $key = "$type/$lid";
                    if (isset($declared[$key])) {
                        throw new LidConflict($type, $lid, $operation->pointer('/data/lid'));
                    }
                    $declared[$key] = true;
                }
            }
        }
    }

    /**
     * @param array<string, true> $declared
     */
    private function assertReferencesResolve(Operation $operation, array $declared): void
    {
        $ref = $operation->ref;
        if ($ref?->lid !== null) {
            $this->assertDeclared($ref->type, $ref->lid, $declared, $operation->pointer('/ref/lid'));
        }

        if (!is_array($operation->data)) {
            return;
        }

        if ($operation->targetsRelationship()) {
            // The data is an identifier, a list of them, or null.
            if (array_is_list($operation->data)) {
                foreach ($operation->data as $index => $identifier) {
                    $this->assertIdentifierResolves($identifier, $declared, $operation->pointer("/data/$index"));
                }
            } else {
                $this->assertIdentifierResolves($operation->data, $declared, $operation->pointer('/data'));
            }

            return;
        }

        // A resource object: its own lid on an update/remove names an
        // existing resource; its relationships may point at earlier ones.
        if ($operation->op !== Operation::ADD && isset($operation->data['lid']) && is_scalar($operation->data['lid'])) {
            $type = $operation->type();
            if ($type !== null) {
                $this->assertDeclared($type, (string) $operation->data['lid'], $declared, $operation->pointer('/data/lid'));
            }
        }

        $relationships = $operation->data['relationships'] ?? null;
        if (!is_array($relationships)) {
            return;
        }

        foreach ($relationships as $name => $relationship) {
            if (!is_array($relationship) || !array_key_exists('data', $relationship)) {
                continue;
            }
            $data = $relationship['data'];
            if (!is_array($data)) {
                continue;
            }
            $base = $operation->pointer("/data/relationships/$name/data");
            if (array_is_list($data)) {
                foreach ($data as $index => $identifier) {
                    $this->assertIdentifierResolves($identifier, $declared, "$base/$index");
                }
            } else {
                $this->assertIdentifierResolves($data, $declared, $base);
            }
        }
    }

    /**
     * @param array<string, true> $declared
     */
    private function assertIdentifierResolves(mixed $identifier, array $declared, string $pointer): void
    {
        if (!is_array($identifier) || isset($identifier['id']) || !isset($identifier['lid'])) {
            return;
        }
        if (!is_scalar($identifier['lid']) || !isset($identifier['type']) || !is_string($identifier['type'])) {
            return;
        }

        $this->assertDeclared($identifier['type'], (string) $identifier['lid'], $declared, $pointer);
    }

    /**
     * @param array<string, true> $declared
     */
    private function assertDeclared(string $type, string $lid, array $declared, string $pointer): void
    {
        if (!isset($declared["$type/$lid"])) {
            throw new LidUnresolved($type, $lid, $pointer);
        }
    }
}
