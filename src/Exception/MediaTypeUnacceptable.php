<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * The client's `Accept` header admits no media type this endpoint can produce.
 */
class MediaTypeUnacceptable extends AbstractJsonApiException
{
    public function __construct(private readonly string $mediaType)
    {
        parent::__construct(
            "The media type '$mediaType' is unacceptable in the 'Accept' header.",
            406,
            'MEDIA_TYPE_UNACCEPTABLE',
            ['parameter' => 'accept'],
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
