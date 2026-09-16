<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Hands out a {@see ResourceSchema} for any entity class, memoised.
 *
 * Includes and relationship linkage cross into other entities, so the query
 * components need schemas for classes other than the one being queried.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class SchemaRegistry
{
    /** @var array<class-string, ResourceSchema> */
    private array $schemas = [];

    /**
     * @param array<string, mixed> $config The full configurator output.
     */
    public function __construct(
        private readonly array $config,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param class-string $class
     */
    public function of(string $class): ResourceSchema
    {
        return $this->schemas[$class] ??= new ResourceSchema(
            $this->config,
            $class,
            $this->em->getClassMetadata($class),
        );
    }
}
