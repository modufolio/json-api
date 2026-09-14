<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Modufolio\JsonApi\Exception\OperationMalformed;

/**
 * Parses and validates an `atomic:operations` request document.
 *
 * Structural rules come from the extension: the member is a non-empty
 * array, every entry is an object with a valid `op`, `ref` and `href` are
 * mutually exclusive, a `ref` names a type and exactly one of `id`/`lid`,
 * an `update` or `remove` must say what it targets. Anything else is a 400
 * whose `source.pointer` names the offending member, as the extension
 * requires — and it is raised before any operation runs, so a malformed
 * document never half-executes.
 *
 * Semantic checks — does the type exist, is the relationship writable — are
 * the handler's, since only it knows.
 */
final class OperationsDocument
{
    /**
     * @param array<string, mixed> $payload       The decoded request body
     * @param int|null             $maxOperations Refuse a document with more operations than this;
     *                                            null for no limit. A request that runs a thousand
     *                                            writes in one transaction is a load a server should
     *                                            opt into, not absorb by default.
     *
     * @return list<Operation>
     *
     * @throws OperationMalformed
     */
    public static function parse(array $payload, ?int $maxOperations = null): array
    {
        $member = AtomicExtension::OPERATIONS;

        if (!array_key_exists($member, $payload)) {
            throw new OperationMalformed("The request document has no '$member' member.", '');
        }
        foreach (['data', 'errors', 'included', AtomicExtension::RESULTS] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                throw new OperationMalformed(
                    "A document carrying '$member' must not also carry '$forbidden'.",
                    "/$forbidden",
                );
            }
        }

        $operations = $payload[$member];
        if (!is_array($operations) || !array_is_list($operations)) {
            throw new OperationMalformed("'$member' must be an array of operation objects.", "/$member");
        }
        if ($operations === []) {
            throw new OperationMalformed("'$member' must contain at least one operation.", "/$member");
        }
        if ($maxOperations !== null && count($operations) > $maxOperations) {
            throw new OperationMalformed(
                sprintf('This endpoint accepts at most %d operations per request; %d were sent.', $maxOperations, count($operations)),
                "/$member",
            );
        }

        $parsed = [];
        foreach ($operations as $index => $raw) {
            $parsed[] = self::parseOperation($index, $raw);
        }

        return $parsed;
    }

    private static function parseOperation(int $index, mixed $raw): Operation
    {
        $pointer = '/' . AtomicExtension::OPERATIONS . '/' . $index;

        if (!is_array($raw) || array_is_list($raw)) {
            throw new OperationMalformed('An operation must be an object.', $pointer);
        }

        $op = $raw['op'] ?? null;
        if (!is_string($op) || !in_array($op, Operation::OPS, true)) {
            throw new OperationMalformed(
                "'op' must be one of '" . implode("', '", Operation::OPS) . "'.",
                "$pointer/op",
            );
        }

        $hasRef = array_key_exists('ref', $raw);
        $hasHref = array_key_exists('href', $raw);
        if ($hasRef && $hasHref) {
            throw new OperationMalformed("An operation may carry 'ref' or 'href', not both.", $pointer);
        }

        $ref = $hasRef ? self::parseRef($raw['ref'], "$pointer/ref") : null;

        $href = null;
        if ($hasHref) {
            if (!is_string($raw['href']) || trim($raw['href']) === '') {
                throw new OperationMalformed("'href' must be a non-empty URI reference.", "$pointer/href");
            }
            $href = $raw['href'];
        }

        $hasData = array_key_exists('data', $raw);
        $data = $hasData ? $raw['data'] : null;

        $meta = $raw['meta'] ?? [];
        if (!is_array($meta)) {
            throw new OperationMalformed("'meta' must be an object.", "$pointer/meta");
        }

        // What each op needs to know its target.
        if ($op === Operation::ADD && $ref === null && $href === null) {
            if (!is_array($data) || array_is_list($data) || !isset($data['type']) || !is_string($data['type'])) {
                throw new OperationMalformed(
                    "An 'add' without a target must carry a resource object with a 'type' in 'data'.",
                    "$pointer/data",
                );
            }
        }
        if ($op !== Operation::ADD && $ref === null && $href === null) {
            // An update may identify its target through data.type + data.id.
            $targetInData = is_array($data) && !array_is_list($data)
                && isset($data['type'], $data['id']) && is_string($data['type']);
            if ($op !== Operation::UPDATE || !$targetInData) {
                throw new OperationMalformed("A '$op' must say what it targets through 'ref' or 'href'.", $pointer);
            }
        }
        if ($op === Operation::ADD && $ref !== null && !$ref->targetsRelationship()) {
            // Adding to an existing resource means adding to one of its
            // relationships; a bare ref names nothing to add to.
            throw new OperationMalformed("An 'add' with a 'ref' must name the 'relationship' to add to.", "$pointer/ref");
        }
        if ($ref?->targetsRelationship() && !$hasData) {
            throw new OperationMalformed("A relationship operation must carry 'data'.", $pointer);
        }
        if ($op === Operation::REMOVE && $ref !== null && !$ref->targetsRelationship() && $hasData) {
            throw new OperationMalformed("A 'remove' of a resource takes no 'data'.", "$pointer/data");
        }

        /** @var array<string, mixed> $meta */
        return new Operation(
            index: $index,
            op: $op,
            ref: $ref,
            href: $href,
            data: $data,
            hasData: $hasData,
            meta: $meta,
        );
    }

    private static function parseRef(mixed $raw, string $pointer): OperationRef
    {
        if (!is_array($raw) || array_is_list($raw)) {
            throw new OperationMalformed("'ref' must be an object.", $pointer);
        }

        $type = $raw['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new OperationMalformed("'ref' must name a 'type'.", "$pointer/type");
        }

        $id = self::optionalString($raw, 'id', $pointer);
        $lid = self::optionalString($raw, 'lid', $pointer);
        $relationship = self::optionalString($raw, 'relationship', $pointer);

        if ($id === null && $lid === null) {
            throw new OperationMalformed("'ref' must carry an 'id' or a 'lid'.", $pointer);
        }
        if ($id !== null && $lid !== null) {
            throw new OperationMalformed("'ref' may carry 'id' or 'lid', not both.", $pointer);
        }

        foreach (array_keys($raw) as $member) {
            if (!in_array($member, ['type', 'id', 'lid', 'relationship'], true)) {
                throw new OperationMalformed("'ref' does not allow a '$member' member.", "$pointer/$member");
            }
        }

        return new OperationRef(type: $type, id: $id, lid: $lid, relationship: $relationship);
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function optionalString(array $raw, string $member, string $pointer): ?string
    {
        if (!array_key_exists($member, $raw)) {
            return null;
        }
        $value = $raw[$member];
        if (!is_string($value) && !is_int($value)) {
            throw new OperationMalformed("'$member' must be a string.", "$pointer/$member");
        }
        $value = (string) $value;
        if ($value === '') {
            throw new OperationMalformed("'$member' must not be empty.", "$pointer/$member");
        }

        return $value;
    }
}
