<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use JsonSerializable;
use Modufolio\JsonApi\Document\ResourceObject;

/**
 * What one operation produced: an entry of the `atomic:results` array.
 *
 * A result carries `data` — the created or updated resource — and/or `meta`,
 * or nothing at all, which the extension spells as an empty object. A
 * removal, or an update that changed nothing the client did not send,
 * returns {@see none()}.
 */
final class OperationResult implements JsonSerializable
{
    /**
     * @param ResourceObject|array<string, mixed>|list<array<string, mixed>>|null $data
     * @param array<string, mixed>                                              $meta
     */
    public function __construct(
        public readonly ResourceObject|array|null $data = null,
        public readonly bool $hasData = false,
        public readonly array $meta = [],
    ) {
    }

    /**
     * A result with no data: the operation succeeded and there is nothing to say.
     *
     * @param array<string, mixed> $meta
     */
    public static function none(array $meta = []): self
    {
        return new self(data: null, hasData: false, meta: $meta);
    }

    /**
     * A result carrying the resource the operation produced.
     *
     * @param ResourceObject|array<string, mixed>|list<array<string, mixed>>|null $data
     * @param array<string, mixed>                                              $meta
     */
    public static function of(ResourceObject|array|null $data, array $meta = []): self
    {
        return new self(data: $data, hasData: true, meta: $meta);
    }

    public function isEmpty(): bool
    {
        return !$this->hasData && $this->meta === [];
    }

    /**
     * The id of the resource in `data`, when it is a single resource.
     */
    public function resourceId(): ?string
    {
        if ($this->data instanceof ResourceObject) {
            return $this->data->getId();
        }
        if (is_array($this->data) && isset($this->data['id']) && is_scalar($this->data['id'])) {
            return (string) $this->data['id'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public function jsonSerialize(): array|\stdClass
    {
        $result = [];
        if ($this->hasData) {
            $result['data'] = $this->data;
        }
        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }

        // `{}`, not `[]`: an empty PHP array encodes as a JSON list.
        return $result === [] ? new \stdClass() : $result;
    }
}
