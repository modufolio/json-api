<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

interface JsonApiResource
{
    /**
     * Get resource key.
     * JSON API `type`
     *
     * @return string
     */
    public static function getResourceKey(): string;

    /**
     * JSON API `id`
     *
     * @return mixed
     */
    public function getId(): mixed;

    /**
     * Get API fields.
     * Returns the list of fields to expose via the API.
     *
     * @return array<int, string>
     */
    public static function getApiFields(): array;

    /**
     * Get API relationships.
     * Returns the list of relationships to expose via the API.
     *
     * @return array<int, string>
     */
    public static function getApiRelationships(): array;

    /**
     * Get API operations.
     * Returns the list of allowed operations for this resource.
     *
     * @return array<string, bool>
     */
    public static function getApiOperations(): array;
}
