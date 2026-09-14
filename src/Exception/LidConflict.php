<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * Two resources of one type claimed the same `lid` within a request.
 *
 * The specification requires a `lid` to be unique per type across the
 * document; a document that breaks that is malformed, hence a 400 rather
 * than a 409 — the conflict is inside the request, not with server state.
 */
class LidConflict extends AbstractJsonApiException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $lid,
        string $pointer = '',
    ) {
        parent::__construct(
            message: "The lid '$lid' is already used by another $resourceType resource in this request.",
            status: 400,
            errorCode: 'LID_CONFLICT',
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
        return 'The local identifier is already in use';
    }
}
