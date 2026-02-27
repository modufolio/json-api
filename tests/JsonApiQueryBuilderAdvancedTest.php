<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class JsonApiQueryBuilderAdvancedTest extends TestCase
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
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
            Account::class => [
                'resource_key' => 'account',
                'fields' => ['id', 'name'],
                'relationships' => ['contacts', 'organizations'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
            Organization::class => [
                'resource_key' => 'organization',
                'fields' => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);

        $organization = new Organization();
        $organization->setName('Test Org');
        $organization->setEmail('org@test.com');
        $organization->setAccount($account);
        $this->em->persist($organization);

        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        $contact->setEmail('john@test.com');
        $contact->setAccount($account);
        $this->em->persist($contact);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testIncludeWithManyToOneRelationship(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder->include(['account'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);

        $contact = $result['data'][0];
        $this->assertArrayHasKey('id', $contact);
        $this->assertArrayHasKey('attributes', $contact);
        $this->assertEquals('John', $contact['attributes']['first_name']);
    }

    public function testIncludeWithOneToManyRelationship(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        $result = $queryBuilder->include(['contacts'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);

        $account = $result['data'][0];
        $this->assertArrayHasKey('id', $account);
        $this->assertArrayHasKey('attributes', $account);
    }

    public function testIncludeWithUnknownAssociation(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relationship: nonexistent');

        $queryBuilder->include(['nonexistent'])->operation('index')->get();
    }

    public function testBasicIndexOperation(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
    }

    public function testShowOperationWithNoData(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder->operation('show')->withId('999')->get();

        $this->assertEquals([], $result);
    }

    public function testFilteringOperations(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder->filter(['firstName' => 'John'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testComplexFilteringWithInOperator(): void
    {
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'Test Account']);

        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('Smith');
        $contact2->setEmail('jane@test.com');
        $contact2->setAccount($account);
        $this->em->persist($contact2);
        $this->em->flush();

        // Build QB after all data is inserted
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Verify both contacts exist in DB before filtering
        $all = $queryBuilder->operation('index')->get();
        $this->assertCount(2, $all['data']);

        $result = $queryBuilder
            ->filter(['firstName' => ['in' => ['John', 'Jane']]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        $names = array_map(fn($c) => $c['attributes']['first_name'], $result['data']);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testComplexUriBuilding(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $uri = $queryBuilder
            ->fields(['firstName', 'email'])
            ->filter(['firstName' => ['like' => '%John%']])
            ->include(['account'])
            ->sort(['-firstName', 'email'])
            ->page(2, 10)
            ->buildUri();

        $this->assertIsString($uri);
        $this->assertStringContainsString('/contact', $uri);
        $this->assertStringContainsString('page[number]=2', $uri);
        $this->assertStringContainsString('page[size]=10', $uri);
    }

    public function testInvalidFieldValidation(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalidField');

        $queryBuilder->filter(['invalidField' => 'test'])->operation('index')->get();
    }

    public function testUnsupportedOperation(): void
    {
        $restrictedConfig = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName'],
                'relationships' => [],
                'operations' => ['index' => true],
            ],
        ];

        $queryBuilder = new JsonApiQueryBuilder(
            $restrictedConfig,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation create not supported');

        $queryBuilder->operation('create');
    }

    public function testInvalidOperation(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation invalidOp not supported');

        $queryBuilder->operation('invalidOp');
    }
}
