<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderFieldsTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);

        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        $contact->setEmail('john@example.com');
        $contact->setAccount($account);
        $this->em->persist($contact);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testIndexReturnsDataWithAttributes(): void
    {
        $qb = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $qb->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);

        $item = $result['data'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('attributes', $item);
        $this->assertArrayHasKey('first_name', $item['attributes']);
        $this->assertArrayHasKey('last_name', $item['attributes']);
        $this->assertArrayHasKey('email', $item['attributes']);
        $this->assertEquals('John', $item['attributes']['first_name']);
        $this->assertEquals('john@example.com', $item['attributes']['email']);
    }

    public function testIdIsSeparatedFromAttributes(): void
    {
        $qb = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $qb->operation('index')->get();

        $item = $result['data'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayNotHasKey('id', $item['attributes']);
    }

    public function testSparseFieldsetLimitsAttributes(): void
    {
        $qb = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $qb->fields(['firstName'])->operation('index')->get();

        $item = $result['data'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('first_name', $item['attributes']);
        $this->assertArrayNotHasKey('last_name', $item['attributes']);
        $this->assertArrayNotHasKey('email', $item['attributes']);
    }

    public function testEmptyAttributesWhenOnlyIdSelected(): void
    {
        $qb = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $qb->fields(['id'])->operation('index')->get();

        $item = $result['data'][0];
        $this->assertArrayHasKey('id', $item);
        $this->assertIsArray($item['attributes']);
        $this->assertEmpty($item['attributes']);
    }
}
