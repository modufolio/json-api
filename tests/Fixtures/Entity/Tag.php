<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Modufolio\JsonApi\JsonApiResource;

/**
 * The inverse side of a ManyToMany, so both directions of the association can
 * be exercised: `Contact::$tags` owns the join table, `Tag::$contacts` reads
 * the same table with its two columns swapped.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tags')]
class Tag implements JsonApiResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $label = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $color = null;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\ManyToMany(targetEntity: Contact::class, mappedBy: 'tags')]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public static function getResourceKey(): string
    {
        return 'tag';
    }

    public static function getApiFields(): array
    {
        return ['id', 'label', 'color'];
    }

    public static function getApiRelationships(): array
    {
        return ['contacts'];
    }

    public static function getApiOperations(): array
    {
        return [
            'index'  => true,
            'show'   => true,
            'create' => false,
            'update' => false,
            'delete' => false,
        ];
    }
}
