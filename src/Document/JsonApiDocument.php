<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

class JsonApiDocument implements \JsonSerializable
{
    private array $document = [];

    public function __construct()
    {
        // Add JSON:API version information (required by spec)
        $this->document['jsonapi'] = [
            'version' => '1.1'
        ];
    }

    /**
     * Set the primary data for the document
     *
     * @param ResourceObject|array<ResourceObject>|ResourceIdentifierObject|array<ResourceIdentifierObject>|null $data
     * @return self
     */
    public function setData($data): self
    {
        if (isset($this->document['errors'])) {
            throw new \LogicException('Cannot include both data and errors in a JSON:API document');
        }

        $this->document['data'] = $data;
        return $this;
    }

    /**
     * Set the errors for the document
     *
     * @param array<ErrorObject> $errors
     * @return self
     */
    public function setErrors(array $errors): self
    {
        if (isset($this->document['data'])) {
            throw new \LogicException('Cannot include both data and errors in a JSON:API document');
        }

        $this->document['errors'] = $errors;
        return $this;
    }

    /**
     * Add metadata to the document
     *
     * @param array<string, mixed> $meta
     * @return self
     */
    public function setMeta(array $meta): self
    {
        $this->document['meta'] = $meta;
        return $this;
    }

    /**
     * Set the included resources
     *
     * @param array<ResourceObject> $included
     * @return self
     */
    public function setIncluded(array $included): self
    {
        if (!isset($this->document['data'])) {
            throw new \LogicException('Cannot include resources without primary data');
        }

        $this->document['included'] = $included;
        return $this;
    }

    /**
     * Set links for the document
     *
     * @param array<string, string|array<string, mixed>> $links
     * @return self
     */
    public function setLinks(array $links): self
    {
        $this->document['links'] = $links;
        return $this;
    }

    /**
     * Set JSON:API implementation info
     *
     * @param array<string, mixed> $jsonapi
     * @return self
     */
    public function setJsonApi(array $jsonapi): self
    {
        $this->document['jsonapi'] = $jsonapi;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    public function toArray(): array
    {
        return $this->document;
    }
}
