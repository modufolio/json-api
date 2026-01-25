<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity\Traits;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;

trait SoftDeleteable
{
    #[Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeInterface $deletedAt = null;

    /**
     * Soft delete the entity by setting the deletedAt timestamp
     */
    public function softDelete(): void
    {
        $this->deletedAt = new DateTimeImmutable();
    }

    /**
     * Restore a soft-deleted entity by clearing the deletedAt timestamp
     */
    public function restore(): void
    {
        $this->deletedAt = null;
    }

    /**
     * Check if the entity is soft deleted
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Get the deletion timestamp
     */
    public function getDeletedAt(): ?DateTimeInterface
    {
        return $this->deletedAt;
    }

}
