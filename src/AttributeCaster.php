<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Modufolio\JsonApi\Exception\InvalidAttributeValueException;

/**
 * Casts a raw attribute value from a JSON:API document to the PHP type the
 * entity's Doctrine mapping declares.
 */
final class AttributeCaster
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param object|class-string $entity Entity instance or class name
     * @param string              $field  Entity field (property) name, not the
     *                                    JSON:API attribute name
     *
     * @throws InvalidAttributeValueException when the value cannot be cast
     */
    public function cast(object|string $entity, string $field, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        $metadata = $this->em->getClassMetadata(is_object($entity) ? $entity::class : $entity);

        // Associations are resolved by the caller (it has to load the related
        // entity); anything unmapped is not ours to interpret.
        if (!$metadata->hasField($field)) {
            return $value;
        }

        $enumType = $metadata->getFieldMapping($field)->enumType ?? null;

        if (null !== $enumType) {
            return $this->toEnum($enumType, $field, $value);
        }

        // Only text needs parsing. A JSON document already produces real ints,
        // floats and booleans, and passing those through untouched avoids two
        // traps: DBAL's boolean cast reads the string "false" as true, and its
        // json type expects a string rather than the array json_decode gave us.
        if (!is_string($value)) {
            return $value;
        }

        $type = $metadata->getTypeOfField($field);

        if (null === $type) {
            return $value;
        }

        // A string reaching a boolean field did not come from a JSON literal,
        // so apply filter_var's rules rather than DBAL's (bool) cast, which
        // would read "false" as true and silently flip the flag on.
        if ('boolean' === $type) {
            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? throw $this->cannotCast($field, $value, 'boolean');
        }

        try {
            return Type::getType($type)->convertToPHPValue(
                $value,
                $this->em->getConnection()->getDatabasePlatform(),
            );
        } catch (\Throwable $e) {
            throw $this->cannotCast($field, $value, $type, $e);
        }
    }

    /**
     * @param class-string<\BackedEnum> $enumType
     */
    private function toEnum(string $enumType, string $field, mixed $value): \BackedEnum
    {
        if ($value instanceof $enumType) {
            return $value;
        }

        if (!is_int($value) && !is_string($value)) {
            throw $this->cannotCast($field, $value, $enumType);
        }

        $accepted = array_map(
            static fn (\BackedEnum $case): string|int => $case->value,
            $enumType::cases(),
        );

        try {
            return $enumType::from($value);
        } catch (\ValueError $e) {
            // Listing the accepted values turns a bare rejection into something
            // the client can act on without reading the source.
            throw new InvalidAttributeValueException(sprintf('Invalid value %s for "%s". Expected one of: %s.', var_export($value, true), $field, implode(', ', array_map(strval(...), $accepted))), $field, $accepted, $e);
        }
    }

    /**
     * @param list<string|int> $accepted
     */
    private function cannotCast(string $field, mixed $value, string $type, ?\Throwable $previous = null, array $accepted = []): InvalidAttributeValueException
    {
        return new InvalidAttributeValueException(
            sprintf(
                'Invalid value %s for "%s": expected %s.',
                is_scalar($value) ? var_export($value, true) : get_debug_type($value),
                $field,
                $type,
            ),
            $field,
            $accepted,
            $previous,
        );
    }
}
