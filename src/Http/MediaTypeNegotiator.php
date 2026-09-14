<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Http;

use Modufolio\JsonApi\Exception\MediaTypeUnacceptable;
use Modufolio\JsonApi\Exception\MediaTypeUnsupported;

/**
 * Applies the JSON:API 1.1 content negotiation rules to a request.
 *
 * Both methods answer the same question — "which JSON:API variant does this
 * request and this server agree on?" — for the two headers a request carries:
 *
 * - {@see negotiateContentType()} reads the body's media type and throws the
 *   415 the specification demands for a parameter other than `ext`/`profile`
 *   or for an extension this server does not implement.
 * - {@see negotiateAccept()} walks the client's preferences, skips every
 *   JSON:API instance modified by a foreign parameter, and throws the 406
 *   when nothing acceptable is left.
 *
 * Each returns the {@see MediaType} the response must be sent as: the
 * negotiated extensions and the supported profiles, which
 * {@see ResponseFactory::jsonApi()} writes into `Content-Type` and
 * {@see \Modufolio\JsonApi\Document\JsonApiDocument::setMediaType()} into the
 * `jsonapi` object. Unsupported profiles are dropped silently, as the
 * specification allows; unsupported extensions are not, because a document
 * that relies on one cannot be processed correctly without it.
 *
 * The library ships no endpoint, so this is vocabulary for your controller:
 * run it before reading the body, catch {@see \Modufolio\JsonApi\Exception\JsonApiExceptionInterface}
 * alongside the others.
 */
final class MediaTypeNegotiator
{
    /** @var list<string> */
    private readonly array $supportedExtensions;
    /** @var list<string> */
    private readonly array $supportedProfiles;
    /** @var list<string> */
    private readonly array $otherContentTypes;

    /**
     * @param list<string> $supportedExtensions Extension URIs this server implements, e.g. `AtomicOperations::URI`
     * @param list<string> $supportedProfiles   Profile URIs this server applies when asked
     * @param list<string> $otherContentTypes   Non-JSON:API body types the endpoint also reads (`application/json`),
     *                                          accepted with any parameters
     */
    public function __construct(
        array $supportedExtensions = [],
        array $supportedProfiles = [],
        array $otherContentTypes = [],
    ) {
        $this->supportedExtensions = $supportedExtensions;
        $this->supportedProfiles = $supportedProfiles;
        $this->otherContentTypes = array_map(strtolower(...), $otherContentTypes);
    }

    /**
     * The media type of a request body, checked against what this server reads.
     *
     * @throws MediaTypeUnsupported (415) for an unparsable or foreign type, a JSON:API type
     *                              modified by a parameter other than `ext`/`profile`, or
     *                              an `ext` naming an extension this server does not support
     */
    public function negotiateContentType(string $header): MediaType
    {
        $header = trim($header);
        if ($header === '') {
            throw new MediaTypeUnsupported(mediaType: '', detail: 'The request carries a body but no Content-Type header.');
        }

        try {
            $mediaType = MediaType::parse($header);
        } catch (\InvalidArgumentException $e) {
            throw new MediaTypeUnsupported(mediaType: $header, detail: $e->getMessage());
        }

        if (!$mediaType->isJsonApi()) {
            if (in_array($mediaType->type, $this->otherContentTypes, true)) {
                return $mediaType;
            }
            throw new MediaTypeUnsupported($header);
        }

        if (!$mediaType->hasOnlyJsonApiParameters()) {
            throw new MediaTypeUnsupported(
                mediaType: $header,
                detail: sprintf(
                    "The JSON:API media type accepts only the 'ext' and 'profile' parameters; '%s' is not allowed.",
                    implode("', '", array_keys($mediaType->parameters)),
                ),
            );
        }

        $unsupported = array_diff($mediaType->extensions, $this->supportedExtensions);
        if ($unsupported !== []) {
            throw new MediaTypeUnsupported(
                mediaType: $header,
                detail: sprintf('The extension %s is not supported by this endpoint.', implode(', ', $unsupported)),
            );
        }

        return $mediaType->withProfiles($this->applicableProfiles($mediaType->profiles));
    }

    /**
     * The media type the response must be sent as, given the client's `Accept`.
     *
     * A missing header, `*​/*` or `application/*` accepts the plain JSON:API
     * media type. Instances of the JSON:API type modified by a parameter other
     * than `ext`/`profile` are ignored, as the specification says; among the
     * rest the most preferred whose extensions are all supported wins.
     *
     * @throws MediaTypeUnacceptable (406) when the header names JSON:API only in
     *                                forms this server cannot produce, or admits
     *                                no JSON:API type at all
     */
    public function negotiateAccept(string $header): MediaType
    {
        $header = trim($header);
        if ($header === '') {
            return MediaType::jsonApi();
        }

        $candidates = MediaType::parseList($header);
        $sawJsonApi = false;

        foreach ($candidates as $candidate) {
            if ($candidate->quality <= 0.0 || !$candidate->matchesJsonApi()) {
                continue;
            }

            if (!$candidate->isJsonApi()) {
                // A wildcard: the client takes whatever we send. Plain JSON:API.
                return MediaType::jsonApi();
            }

            $sawJsonApi = true;

            if (!$candidate->hasOnlyJsonApiParameters()) {
                continue;
            }
            if (array_diff($candidate->extensions, $this->supportedExtensions) !== []) {
                continue;
            }

            return MediaType::jsonApi(
                extensions: $candidate->extensions,
                profiles: $this->applicableProfiles($candidate->profiles),
            );
        }

        throw new MediaTypeUnacceptable(
            mediaType: $header,
            detail: $sawJsonApi
                ? 'Every JSON:API media type in the Accept header is modified by a parameter or extension this endpoint does not support.'
                : 'The Accept header admits no JSON:API media type.',
        );
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return $this->supportedExtensions;
    }

    /**
     * @return list<string>
     */
    public function supportedProfiles(): array
    {
        return $this->supportedProfiles;
    }

    /**
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private function applicableProfiles(array $requested): array
    {
        return array_values(array_intersect($requested, $this->supportedProfiles));
    }
}
