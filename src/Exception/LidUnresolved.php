<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * A resource identifier names a `lid` no resource in the request has claimed.
 *
 * A `lid` only means something once the resource carrying it has been
 * created — in an earlier atomic operation, or in the primary data of the
 * same document. A reference to one that never was is a malformed request.
 */
class LidUnresolved extends AbstractJsonApiException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $lid,
        string $pointer = '',
    ) {
        parent::__construct(
            message: "No $resourceType resource in this request carries the lid '$lid'.",
            status: 400,
            errorCode: 'LID_UNRESOLVED',
            source: $pointer !== '' ? ['pointer' => $pointer] : [],
        );
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getLid(): string
    {
        return $this->lid;
    }

    protected function title(): string
    {
        return 'The local identifier is unresolved';
    }
}
