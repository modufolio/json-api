<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Document;

use Modufolio\JsonApi\Http\MediaType;

class JsonApiDocument implements \JsonSerializable
{
    /**
     * The highest JSON:API version this library implements — the value of
     * the `jsonapi.version` member every document starts with.
     */
    public const VERSION = '1.1';

    /** @var array<string, mixed> */
    private array $document = [];

    public function __construct()
    {
        // Add JSON:API version information (required by spec)
        $this->document['jsonapi'] = [
            'version' => self::VERSION,
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
     * Values are URL strings, {@see LinkObject}s or link arrays; a pagination
     * link that does not apply is `null`. JSON:API 1.1 lets the top level
     * carry `describedby` as well as `self`, `related` and the pagination
     * links.
     *
     * @param array<string, string|LinkObject|array<string, mixed>|null> $links
     * @return self
     */
    public function setLinks(array $links): self
    {
        $this->document['links'] = $links;
        return $this;
    }

    /**
     * Replace the whole `jsonapi` object.
     *
     * Prefer {@see setExtensions()}, {@see setProfiles()} and
     * {@see setJsonApiMeta()}, which keep the version member in place.
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
     * Declare the extensions applied to this document (`jsonapi.ext`).
     *
     * JSON:API 1.1 requires a document that uses an extension's members to
     * list the extension's URI here, and the response's `Content-Type` to
     * carry the same list in its `ext` parameter — see
     * {@see setMediaType()} for setting both from one negotiated value.
     *
     * @param list<string> $extensions Extension URIs; an empty list removes the member
     * @return self
     */
    public function setExtensions(array $extensions): self
    {
        return $this->setJsonApiMember('ext', $extensions);
    }

    /**
     * Declare the profiles applied to this document (`jsonapi.profile`).
     *
     * @param list<string> $profiles Profile URIs; an empty list removes the member
     * @return self
     */
    public function setProfiles(array $profiles): self
    {
        return $this->setJsonApiMember('profile', $profiles);
    }

    /**
     * Non-standard meta-information about the implementation (`jsonapi.meta`).
     *
     * @param array<string, mixed> $meta An empty array removes the member
     * @return self
     */
    public function setJsonApiMeta(array $meta): self
    {
        return $this->setJsonApiMember('meta', $meta);
    }

    /**
     * Take the applied extensions and profiles from a negotiated media type.
     *
     * The `jsonapi` object and the `Content-Type` header must agree on what
     * was applied; passing the {@see MediaType} returned by
     * {@see \Modufolio\JsonApi\Http\MediaTypeNegotiator} to both this and
     * {@see \Modufolio\JsonApi\Http\ResponseFactory::jsonApi()} keeps them so.
     *
     * @return self
     */
    public function setMediaType(MediaType $mediaType): self
    {
        return $this->setExtensions($mediaType->extensions)->setProfiles($mediaType->profiles);
    }

    /**
     * The `jsonapi` object as it will be emitted.
     *
     * @return array<string, mixed>
     */
    public function getJsonApi(): array
    {
        return $this->document['jsonapi'];
    }

    /**
     * @param list<string>|array<string, mixed> $value
     */
    private function setJsonApiMember(string $member, array $value): self
    {
        if ($value === []) {
            unset($this->document['jsonapi'][$member]);
        } else {
            $this->document['jsonapi'][$member] = $value;
        }

        return $this;
    }

    /**
     * @inheritdoc
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->document;
    }
}
