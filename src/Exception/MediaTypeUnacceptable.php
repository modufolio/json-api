<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * The client's `Accept` header admits no media type this endpoint can produce.
 *
 * The `source` names the `Accept` header through the JSON:API 1.1
 * `source.header` member.
 */
class MediaTypeUnacceptable extends AbstractJsonApiException
{
    public function __construct(private readonly string $mediaType, ?string $detail = null)
    {
        parent::__construct(
            message: $detail ?? "The media type '$mediaType' is unacceptable in the 'Accept' header.",
            status: 406,
            errorCode: 'MEDIA_TYPE_UNACCEPTABLE',
            source: ['header' => 'Accept'],
        );
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    protected function title(): string
    {
        return 'The requested media type cannot be produced';
    }
}
