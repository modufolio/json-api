<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

class ResourceObject implements \JsonSerializable
{
    private string $type;
    private ?string $id = null;
    private ?string $lid = null;
    private array $attributes = [];
    private array $relationships = [];
    private array $links = [];
    private array $meta = [];

    public function __construct(string $type, ?string $id = null)
    {
        $this->type = $type;
        $this->id = $id;
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
     * Set resource attributes
     *
     * @param array<string, mixed> $attributes
     * @return self
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * Add a single attribute
     *
     * @param string $name
     * @param mixed $value
     * @return self
     */
    public function setAttribute(string $name, $value): self
    {
        if (in_array($name, ['id', 'type', 'lid'], true)) {
            throw new \InvalidArgumentException("Cannot use reserved keyword '{$name}' as an attribute name");
        }

        $this->attributes[$name] = $value;
        return $this;
    }

    /**
     * Set relationships for the resource
     *
     * @param array<string, array<string, mixed>> $relationships
     * @return self
     */
    public function setRelationships(array $relationships): self
    {
        $this->relationships = $relationships;
        return $this;
    }

    /**
     * Add a to-one relationship
     *
     * @param string $name
     * @param ResourceIdentifierObject|null $related
     * @param array<string, string> $links
     * @return self
     */
    public function setToOneRelationship(string $name, ?ResourceIdentifierObject $related, array $links = []): self
    {
        if (in_array($name, ['id', 'type', 'lid'], true)) {
            throw new \InvalidArgumentException("Cannot use reserved keyword '{$name}' as a relationship name");
        }

        $this->relationships[$name] = [
            'data' => $related
        ];

        if (!empty($links)) {
            $this->relationships[$name]['links'] = $links;
        }

        return $this;
    }

    /**
     * Add a to-many relationship
     *
     * @param string $name
     * @param array<ResourceIdentifierObject> $related
     * @param array<string, string> $links
     * @return self
     */
    public function setToManyRelationship(string $name, array $related, array $links = []): self
    {
        if (in_array($name, ['id', 'type', 'lid'], true)) {
            throw new \InvalidArgumentException("Cannot use reserved keyword '{$name}' as a relationship name");
        }

        $this->relationships[$name] = [
            'data' => $related
        ];

        if (!empty($links)) {
            $this->relationships[$name]['links'] = $links;
        }

        return $this;
    }

    /**
     * Set links for the resource
     *
     * @param array<string, string|array<string, mixed>> $links
     * @return self
     */
    public function setLinks(array $links): self
    {
        $this->links = $links;
        return $this;
    }

    /**
     * Set meta information for the resource
     *
     * @param array<string, mixed> $meta
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
        $resource = [
            'type' => $this->type
        ];

        if ($this->id !== null) {
            $resource['id'] = $this->id;
        } elseif ($this->lid !== null) {
            $resource['lid'] = $this->lid;
        }

        if (!empty($this->attributes)) {
            $resource['attributes'] = $this->attributes;
        }

        if (!empty($this->relationships)) {
            $resource['relationships'] = $this->relationships;
        }

        if (!empty($this->links)) {
            $resource['links'] = $this->links;
        }

        if (!empty($this->meta)) {
            $resource['meta'] = $this->meta;
        }

        return $resource;
    }
}
