<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * The request body arrived in a media type this endpoint cannot read.
 *
 * Distinct from {@see MediaTypeUnacceptable}, which is about what the client
 * asked to receive.
 */
class MediaTypeUnsupported extends AbstractJsonApiException
{
    public function __construct(private readonly string $mediaType)
    {
        parent::__construct(
            "The media type '$mediaType' is unsupported in the 'Content-Type' header.",
            415,
            'MEDIA_TYPE_UNSUPPORTED',
            ['parameter' => 'content-type'],
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
