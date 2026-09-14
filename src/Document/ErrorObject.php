<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

class ErrorObject implements \JsonSerializable
{
    /** @var array<string, mixed> */
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
     * JSON:API 1.1 names two: `about`, a page describing this occurrence, and
     * `type`, a page describing the error's type in general.
     *
     * @param array<string, string|LinkObject|array<string, mixed>> $links
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
     * The specification names three members, and says an error should carry
     * one of them or none: `pointer` (into the request document), `parameter`
     * (a query parameter) or `header` (a request header). The typed setters
     * below each set exactly one.
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
     * Blame a part of the request document, as a JSON Pointer (RFC 6901):
     * `/data/attributes/title`.
     */
    public function setSourcePointer(string $pointer): self
    {
        return $this->setSource(['pointer' => $pointer]);
    }

    /**
     * Blame a query parameter, as the client wrote it: `filter[author]`.
     */
    public function setSourceParameter(string $parameter): self
    {
        return $this->setSource(['parameter' => $parameter]);
    }

    /**
     * Blame a request header (JSON:API 1.1): `Content-Type`, `Accept`.
     */
    public function setSourceHeader(string $header): self
    {
        return $this->setSource(['header' => $header]);
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
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->error;
    }
}
