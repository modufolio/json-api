<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * The body's resource object names a `type` the endpoint does not serve, or
 * names none at all.
 *
 * JSON:API reserves `409 Conflict` for this: a `POST /articles` whose
 * `data.type` is `users` is not a malformed request but a request for a
 * different collection than the one addressed. The `source` points at
 * `/data/type` so the client knows which member to correct.
 */
class ResourceTypeConflict extends AbstractJsonApiException
{
    public function __construct(
        private readonly string $expectedType,
        private readonly ?string $actualType,
        string $detail,
    ) {
        parent::__construct(
            $detail,
            409,
            'RESOURCE_TYPE_CONFLICT',
            ['pointer' => '/data/type'],
        );
    }

    /**
     * The type the endpoint serves.
     */
    public function getExpectedType(): string
    {
        return $this->expectedType;
    }

    /**
     * The type the body carried, or null when it carried none.
     */
    public function getActualType(): ?string
    {
        return $this->actualType;
    }

    protected function title(): string
    {
        return 'The resource type conflicts with the endpoint';
    }
}
