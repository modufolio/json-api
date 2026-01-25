<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

class ResourceIdentifierObject implements \JsonSerializable
{
    private string $type;
    private ?string $id;
    private ?string $lid;
    private array $meta = [];

    /**
     * @param string $type Resource type
     * @param string|null $id Resource ID (null for client-side resources)
     */
    public function __construct(string $type, ?string $id = null)
    {
        $this->type = $type;
        $this->id = $id;
        $this->lid = null;
    }

    /**
     * Set a local ID for client-side identification
     *
     * @param string $lid
     * @return self
     */
    public function setLid(string $lid): self
    {
        if ($this->id !== null) {
            throw new \LogicException('Cannot set lid when id is present');
        }

        $this->lid = $lid;
        return $this;
    }

    /**
     * Set meta information
     *
     * @param array $meta
     * @return self
     */
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): array
    {
        $identifier = [
            'type' => $this->type
        ];

        if ($this->id !== null) {
            $identifier['id'] = $this->id;
        } elseif ($this->lid !== null) {
            $identifier['lid'] = $this->lid;
        }

        if (!empty($this->meta)) {
            $identifier['meta'] = $this->meta;
        }

        return $identifier;
    }
}
