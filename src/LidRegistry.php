<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Modufolio\JsonApi\Exception\LidConflict;
use Modufolio\JsonApi\Exception\LidUnresolved;

/**
 * Maps the local identifiers of a request to the ids the server assigned.
 *
 * JSON:API 1.1 lets a client name a resource it is about to create with a
 * `lid`, local to the request, and refer to it before the server has minted
 * an id — in a later atomic operation, or in a relationship of the same
 * document. Every such reference has to be resolved once the resource exists;
 * this registry is where the mapping lives for the duration of one request.
 *
 * A `lid` is scoped by type: the specification requires it to be unique per
 * type within the document, not globally, so `articles`/`tmp-1` and
 * `people`/`tmp-1` are two different resources.
 */
final class LidRegistry
{
    /** @var array<string, string> `type/lid` => id */
    private array $ids = [];

    /**
     * Record the id the server gave the resource a client called `$lid`.
     *
     * Registering the same pair twice with the same id is harmless; with a
     * different id it is a conflict, because two operations claimed the same
     * local name for different resources.
     *
     * @throws LidConflict
     */
    public function register(string $type, string $lid, string $id): void
    {
        $key = $this->key($type, $lid);

        if (isset($this->ids[$key]) && $this->ids[$key] !== $id) {
            throw new LidConflict($type, $lid);
        }

        $this->ids[$key] = $id;
    }

    public function has(string $type, string $lid): bool
    {
        return isset($this->ids[$this->key($type, $lid)]);
    }

    /**
     * @throws LidUnresolved when nothing has been registered under the pair
     */
    public function resolve(string $type, string $lid): string
    {
        return $this->ids[$this->key($type, $lid)] ?? throw new LidUnresolved($type, $lid);
    }

    /**
     * The id a resource identifier object refers to — its `id`, or the id its
     * `lid` was registered under.
     *
     * @param array<string, mixed> $identifier A resource identifier object with `type` and `id` or `lid`
     *
     * @throws LidUnresolved
     */
    public function resolveIdentifier(array $identifier, string $pointer = ''): string
    {
        if (isset($identifier['id'])) {
            return (string) $identifier['id'];
        }

        $type = (string) ($identifier['type'] ?? '');
        $lid = (string) ($identifier['lid'] ?? '');

        return $this->ids[$this->key($type, $lid)] ?? throw new LidUnresolved($type, $lid, $pointer);
    }

    /**
     * Every registered mapping, keyed `type/lid`.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->ids;
    }

    private function key(string $type, string $lid): string
    {
        return $type . '/' . $lid;
    }
}
