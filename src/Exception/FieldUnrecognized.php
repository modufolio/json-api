<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * A request names fields the resource does not expose.
 *
 * Raised for the allow-list check that guards `fields[…]`, `filter[…]` and
 * `sort`, so an unknown field is a 400 naming the field rather than a 500 or a
 * silently narrowed result.
 */
class FieldUnrecognized extends AbstractJsonApiException
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        private readonly array $fields,
        string $queryParam = 'fields',
    ) {
        parent::__construct(
            'Invalid fields: ' . implode(', ', $fields),
            400,
            'FIELD_UNRECOGNIZED',
            ['parameter' => $queryParam],
        );
    }

    /**
     * @return list<string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    protected function title(): string
    {
        return 'The requested field is unrecognized';
    }
}
