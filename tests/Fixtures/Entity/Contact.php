<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Modufolio\JsonApi\Tests\Fixtures\Entity\Traits\SoftDeleteable;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Traits\Timestampable;
use Modufolio\JsonApi\JsonApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'contacts')]
#[ORM\HasLifecycleCallbacks]
class Contact implements JsonApiResource
{
    use Timestampable;
    use SoftDeleteable;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotNull]
    #[Assert\Type(Account::class)]
    #[ORM\ManyToOne(inversedBy: 'contacts')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Account $account = null;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    private ?Organization $organization = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 25)]
    #[ORM\Column(name: 'first_name', length: 25, nullable: true)]
    private ?string $firstName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 25)]
    #[ORM\Column(name: 'last_name', length: 25, nullable: true)]
    private ?string $lastName = null;

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
    #[ORM\Column(name: 'postal_code', length: 25, nullable: true)]
    private ?string $postalCode = null;

    public function getId(): ?int
    {
        return $this->id;
    }
    public static function getResourceKey(): string
    {
        return 'contact';
    }

    public static function getApiFields(): array
    {
        return [
            'id',
            'firstName',
            'lastName',
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
            'organization'
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

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getName(): string
    {
        return sprintf('%s %s', $this->getFirstName(), $this->getLastName());
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
}
