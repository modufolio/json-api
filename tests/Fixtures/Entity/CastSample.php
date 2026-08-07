<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Modufolio\JsonApi\Tests\Fixtures\Enum\SampleStatus;

/**
 * Covers the field types AttributeCaster has to deal with. Deliberately not a
 * JsonApiResource: it exists for its mapping metadata, not to be served.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cast_samples')]
class CastSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true, enumType: SampleStatus::class)]
    private ?SampleStatus $status = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedOn = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $active = null;

    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ?SampleStatus
    {
        return $this->status;
    }

    public function setStatus(?SampleStatus $status): void
    {
        $this->status = $status;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getPublishedOn(): ?DateTimeImmutable
    {
        return $this->publishedOn;
    }

    public function setPublishedOn(?DateTimeImmutable $publishedOn): void
    {
        $this->publishedOn = $publishedOn;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): void
    {
        $this->active = $active;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): void
    {
        $this->score = $score;
    }
}
