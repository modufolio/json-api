<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * An `include` path names a relationship the resource does not expose.
 *
 * Carries the offending path rather than only the first segment, so a client
 * asking for `comments.author` learns which half was rejected.
 */
class InclusionUnrecognized extends AbstractJsonApiException
{
    public function __construct(private readonly string $includePath, string $detail)
    {
        parent::__construct(
            $detail,
            400,
            'INCLUSION_UNRECOGNIZED',
            ['parameter' => 'include'],
        );
    }

    public function getIncludePath(): string
    {
        return $this->includePath;
    }

    protected function title(): string
    {
        return 'The requested inclusion is unrecognized';
    }
}
