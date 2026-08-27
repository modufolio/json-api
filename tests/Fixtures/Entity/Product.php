<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Modufolio\JsonApi\JsonApiResource;

/**
 * Fixture for the range, exists and boolean filters.
 *
 * The other fixtures are all strings, dates and relationships, which cannot
 * exercise a numeric bound or a boolean column — and a boolean column is
 * exactly where the engines disagree: PostgreSQL stores a real boolean, MySQL
 * a TINYINT, SQL Server a BIT, SQLite an integer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product implements JsonApiResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    // Nullable so the suite can pin where NULLs land in a sort — the engines
    // disagree, and a non-nullable column cannot express the case.
    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'discontinued_at', nullable: true)]
    private ?\DateTimeImmutable $discontinuedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getDiscontinuedAt(): ?\DateTimeImmutable
    {
        return $this->discontinuedAt;
    }

    public function setDiscontinuedAt(?\DateTimeImmutable $discontinuedAt): self
    {
        $this->discontinuedAt = $discontinuedAt;

        return $this;
    }

    public static function getResourceKey(): string
    {
        return 'product';
    }

    public static function getApiFields(): array
    {
        return ['id', 'name', 'price', 'active', 'discontinuedAt'];
    }

    public static function getApiRelationships(): array
    {
        return [];
    }

    public static function getApiOperations(): array
    {
        return ['index' => true, 'show' => true];
    }
}
