<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures\Entity;

use Modufolio\JsonApi\JsonApiResource;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document implements JsonApiResource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public static function getResourceKey(): string
    {
        return 'document';
    }

    public static function getApiFields(): array
    {
        return ['id', 'title', 'body', 'status'];
    }

    public static function getApiRelationships(): array
    {
        return [];
    }

    public static function getApiOperations(): array
    {
        return ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true];
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): void
    {
        $this->body = $body;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }
}
