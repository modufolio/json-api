<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * A well-formed attribute carrying a value the field cannot accept.
 *
 * The document parsed, the member exists and the JSON type is right — only the
 * value is out of range, which is a semantic failure rather than a malformed
 * request. Callers should answer 422, the same as any other failed validation,
 * not 400.
 *
 * Extends InvalidArgumentException so existing catch blocks keep working; catch
 * this type instead when you want the field name and accepted values to build a
 * JSON:API error object with a source pointer.
 */
class InvalidAttributeValueException extends InvalidArgumentException
{
    /**
     * @param string            $field    Entity field (property) name
     * @param list<string|int>  $accepted Values the field would have taken, when
     *                                    that set is finite — empty otherwise
     */
    public function __construct(
        string $message,
        private readonly string $field,
        private readonly array $accepted = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @return list<string|int> Empty when the valid set is open-ended, as it is
     *                          for a date or a number
     */
    public function getAccepted(): array
    {
        return $this->accepted;
    }
}
