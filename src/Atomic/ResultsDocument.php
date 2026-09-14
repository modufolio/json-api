<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use JsonSerializable;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Http\MediaType;

/**
 * The response to an atomic operations request: `atomic:results`, one entry
 * per operation, in order.
 *
 * The `jsonapi` object always lists the extension, as the specification
 * requires of any document using its members; {@see mediaType()} gives the
 * matching `Content-Type`. When no result carries anything,
 * {@see isEmpty()} is true and a 204 with no body is the right response.
 */
final class ResultsDocument implements JsonSerializable
{
    /** @var list<OperationResult> */
    private array $results = [];
    /** @var array<string, mixed> */
    private array $meta = [];
    /** @var list<string> */
    private array $profiles = [];

    public function add(OperationResult $result): self
    {
        $this->results[] = $result;
        return $this;
    }

    /**
     * @return list<OperationResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * Profiles applied to the response, beside the extension.
     *
     * @param list<string> $profiles
     */
    public function setProfiles(array $profiles): self
    {
        $this->profiles = $profiles;
        return $this;
    }

    /**
     * True when every result is empty and the document carries no meta — the
     * case the extension lets a server answer with `204 No Content`.
     */
    public function isEmpty(): bool
    {
        if ($this->meta !== []) {
            return false;
        }
        foreach ($this->results as $result) {
            if (!$result->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The media type this document must be sent as.
     */
    public function mediaType(): MediaType
    {
        return MediaType::jsonApi(extensions: [AtomicExtension::URI], profiles: $this->profiles);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $jsonapi = [
            'version' => JsonApiDocument::VERSION,
            'ext' => [AtomicExtension::URI],
        ];
        if ($this->profiles !== []) {
            $jsonapi['profile'] = $this->profiles;
        }

        $document = [
            'jsonapi' => $jsonapi,
            AtomicExtension::RESULTS => $this->results,
        ];
        if ($this->meta !== []) {
            $document['meta'] = $this->meta;
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
