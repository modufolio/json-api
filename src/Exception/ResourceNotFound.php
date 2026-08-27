<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * No record matches the requested identifier.
 *
 * The query builder does not throw this — `show` reports a miss as
 * `['data' => null]` so a caller can decide between 404 and a null
 * relationship. This exists for controllers that would rather raise than
 * branch, and so the status and code stay consistent when they do.
 */
class ResourceNotFound extends AbstractJsonApiException
{
    public function __construct(
        private readonly string $resourceType,
        private readonly string $id,
    ) {
        parent::__construct(
            "No $resourceType exists with id '$id'.",
            404,
            'RESOURCE_NOT_FOUND',
        );
    }

    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    public function getId(): string
    {
        return $this->id;
    }

    protected function title(): string
    {
        return 'The requested resource is not found';
    }
}
