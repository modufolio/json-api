<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Modufolio\JsonApi\Tests\Fixtures\Entity\Traits\SoftDeleteable;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Traits\Timestampable;
use Modufolio\JsonApi\JsonApiResource;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'organizations')]
#[ORM\HasLifecycleCallbacks]
class Organization implements JsonApiResource
{
    use Timestampable;
    use SoftDeleteable;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[Assert\NotNull]
    #[Assert\Type(Account::class)]
    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'organizations')]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[Assert\Length(max: 50)]
    #[Assert\Email]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $email = null;

    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[Assert\Length(max: 150)]
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $address = null;

    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $city = null;

    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $region = null;

    #[Assert\Length(max: 2)]
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    #[Assert\Length(max: 25)]
    #[ORM\Column(length: 25, nullable: true)]
    private ?string $postalCode = null;

    /**
     * @var Collection<int, Contact>
     */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'organization')]
    #[ORM\OrderBy(['lastName' => 'ASC', 'firstName' => 'ASC'])]
    private Collection $contacts;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public static function getResourceKey(): string
    {
        return 'organization';
    }

    public static function getApiFields(): array
    {
        return [
            'id',
            'name',
            'email',
            'phone',
            'address',
            'city',
            'region',
            'country',
            'postalCode',
            'createdAt',
            'updatedAt',
            'deletedAt'
        ];
    }

    public static function getApiRelationships(): array
    {
        return [
            'account',
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

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): void
    {
        $this->account = $account;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): void
    {
        $this->region = $region;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): void
    {
        $this->country = $country;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): void
    {
        $this->postalCode = $postalCode;
    }


    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts[] = $contact;
            $contact->setOrganization($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            // set the owning side to null (unless already changed)
            if ($contact->getOrganization() === $this) {
                $contact->setOrganization(null);
            }
        }

        return $this;
    }
}
