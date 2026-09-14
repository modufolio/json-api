<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * The request body arrived in a media type this endpoint cannot read.
 *
 * Distinct from {@see MediaTypeUnacceptable}, which is about what the client
 * asked to receive. The `source` names the `Content-Type` header, the
 * JSON:API 1.1 `source.header` member being made for exactly this.
 */
class MediaTypeUnsupported extends AbstractJsonApiException
{
    public function __construct(private readonly string $mediaType, ?string $detail = null)
    {
        parent::__construct(
            message: $detail ?? "The media type '$mediaType' is unsupported in the 'Content-Type' header.",
            status: 415,
            errorCode: 'MEDIA_TYPE_UNSUPPORTED',
            source: ['header' => 'Content-Type'],
        );
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    protected function title(): string
    {
        return 'The provided media type is unsupported';
    }
}
