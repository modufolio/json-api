<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

class ErrorObject implements \JsonSerializable
{
    private array $error = [];

    /**
     * Set the unique identifier for this occurrence of the error
     *
     * @param string $id
     * @return self
     */
    public function setId(string $id): self
    {
        $this->error['id'] = $id;
        return $this;
    }

    /**
     * Set links that lead to further details about this error
     *
     * @param array<string, string|array<string, mixed>> $links
     * @return self
     */
    public function setLinks(array $links): self
    {
        $this->error['links'] = $links;
        return $this;
    }

    /**
     * Set the HTTP status code for this error
     *
     * @param int $status
     * @return self
     */
    public function setStatus(int $status): self
    {
        $this->error['status'] = (string)$status;
        return $this;
    }

    /**
     * Set the application-specific error code
     *
     * @param string $code
     * @return self
     */
    public function setCode(string $code): self
    {
        $this->error['code'] = $code;
        return $this;
    }

    /**
     * Set the short, human-readable summary of the problem
     *
     * @param string $title
     * @return self
     */
    public function setTitle(string $title): self
    {
        $this->error['title'] = $title;
        return $this;
    }

    /**
     * Set the human-readable explanation specific to this error
     *
     * @param string $detail
     * @return self
     */
    public function setDetail(string $detail): self
    {
        $this->error['detail'] = $detail;
        return $this;
    }

    /**
     * Set the source of the error
     *
     * @param array<string, string> $source
     * @return self
     */
    public function setSource(array $source): self
    {
        $this->error['source'] = $source;
        return $this;
    }

    /**
     * Set non-standard meta information about the error
     *
     * @param array<string, mixed> $meta
     * @return self
     */
    public function setMeta(array $meta): self
    {
        $this->error['meta'] = $meta;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): array
    {
        return $this->error;
    }
}
