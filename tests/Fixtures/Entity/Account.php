<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Modufolio\JsonApi\Tests\Fixtures\Entity\Traits\Timestampable;
use Modufolio\JsonApi\JsonApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\HasLifecycleCallbacks]
class Account implements JsonApiResource
{
    use Timestampable;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $name = null;

    /**
     * @var Collection<int, Organization>
     */
    #[ORM\OneToMany(targetEntity: Organization::class, mappedBy: 'account')]
    private Collection $organizations;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'account', orphanRemoval: true)]
    private Collection $contacts;

    public function __construct()
    {
        $this->organizations = new ArrayCollection();
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public static function getResourceKey(): string
    {
        return 'account';
    }

    public static function getApiFields(): array
    {
        return [
            'id',
            'name',
            'createdAt',
            'updatedAt'
        ];
    }

    public static function getApiRelationships(): array
    {
        return [
            'organizations',
            'contacts'
        ];
    }

    public static function getApiOperations(): array
    {
        return [
            'index' => true,
            'show' => true,
            'create' => true,
            'update' => true,
            'delete' => true
        ];
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return Collection<int, Organization>
     */
    public function getOrganizations(): Collection
    {
        return $this->organizations;
    }

    public function addOrganization(Organization $organization): void
    {
        if ($this->organizations->contains($organization)) {
            return;
        }

        $this->organizations[] = $organization;
        $organization->setAccount($this);
    }

    public function removeOrganization(Organization $organization): void
    {
        if (!$this->organizations->removeElement($organization)) {
            return;
        }

        // set the owning side to null (unless already changed)
        if ($organization->getAccount() !== $this) {
            return;
        }

        $organization->setAccount(null);
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): void
    {
        if ($this->contacts->contains($contact)) {
            return;
        }

        $this->contacts[] = $contact;
        $contact->setAccount($this);
    }

    public function removeContact(Contact $contact): void
    {
        if (!$this->contacts->removeElement($contact)) {
            return;
        }

        // set the owning side to null (unless already changed)
        if ($contact->getAccount() !== $this) {
            return;
        }

        $contact->setAccount(null);
    }
}
