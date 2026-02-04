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

class JsonApiQueryBuilderEdgeCasesTest extends TestCase
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
                'relationships' => ['contacts', 'organizations'], // Organizations is OneToMany from Account
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
            Organization::class => [
                'resource_key' => 'organization',
                'fields' => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
            ],
        ];

        // Create test data
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

    public function testBuildSelectWithComplexRelationshipFields(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        // Test with included relationships that should trigger complex select building
        $result = $queryBuilder->include(['contacts'])->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
    }

    public function testPaginationLimitsAndOffsets(): void
    {
        // Get existing account
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'Test Account']);
        $this->assertNotNull($account);
        
        // Create additional contacts for pagination testing
        for ($i = 2; $i <= 10; $i++) {
            $contact = new Contact();
            $contact->setFirstName("Contact{$i}");
            $contact->setLastName("User{$i}");
            $contact->setEmail("contact{$i}@test.com");
            $contact->setAccount($account);
            $this->em->persist($contact);
        }
        $this->em->flush();

        // Verify we have enough data
        $totalContacts = $this->em->getRepository(Contact::class)->count([]);
        $this->assertGreaterThanOrEqual(10, $totalContacts);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test pagination with limit and offset
        $result = $queryBuilder->page(1, 3)->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertLessThanOrEqual(3, count($result['data']));
    }

    public function testSortingMultipleFields(): void
    {
        // Get existing account
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'Test Account']);
        $this->assertNotNull($account);
        
        // Create additional contacts for sorting
        $contact2 = new Contact();
        $contact2->setFirstName('Alice');
        $contact2->setLastName('Smith');
        $contact2->setEmail('alice@test.com');
        $contact2->setAccount($account);
        $this->em->persist($contact2);

        $contact3 = new Contact();
        $contact3->setFirstName('Bob');
        $contact3->setLastName('Johnson');
        $contact3->setEmail('bob@test.com');
        $contact3->setAccount($account);
        $this->em->persist($contact3);
        
        $this->em->flush();

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test sorting by multiple fields
        $result = $queryBuilder->sort(['firstName', '-lastName'])->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThanOrEqual(3, count($result['data']));
        
        // Verify first result is Alice (alphabetical order)
        if (count($result['data']) >= 3) {
            $this->assertEquals('Alice', $result['data'][0]['attributes']['first_name']); // snake_case
        }
    }

    public function testComplexFilteringScenarios(): void
    {
        // Get existing account
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'Test Account']);
        $this->assertNotNull($account);
        
        // Create contacts with different attributes for filtering
        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('Smith');
        $contact2->setEmail('jane@example.com');
        $contact2->setAccount($account);
        $this->em->persist($contact2);
        $this->em->flush();

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test like filtering
        $result = $queryBuilder->filter(['firstName' => ['like' => '%Jane%']])->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        
        $contact = $result['data'][0];
        $this->assertNotNull($contact);
        $this->assertArrayHasKey('attributes', $contact);
        $this->assertEquals('Jane', $contact['attributes']['first_name']); // snake_case
    }

    public function testFieldSelectionWithSparseFieldsets(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test selecting only specific fields
        $result = $queryBuilder->fields(['firstName', 'email'])->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        
        $contact = $result['data'][0];
        $this->assertNotNull($contact);
        $this->assertArrayHasKey('attributes', $contact);
        $this->assertArrayHasKey('first_name', $contact['attributes']); // snake_case
        $this->assertArrayHasKey('email', $contact['attributes']);
        $this->assertArrayNotHasKey('last_name', $contact['attributes']); // snake_case
    }

    public function testGroupByWithHavingClauses(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test grouping with having clause
        $result = $queryBuilder
            ->group('firstName')
            ->having('COUNT(*) >= :min', ['min' => 1])
            ->operation('index')
            ->get();
        
        $this->assertIsArray($result);
    }

    public function testBasicQueryOperations(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test basic index operation
        $result = $queryBuilder->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, count($result['data']));
    }

    public function testShowOperationWithExistingData(): void
    {
        // Get the ID of our test contact
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['firstName' => 'John']);
        $this->assertNotNull($contact, 'Test contact should exist');
        $contactId = $contact->getId();

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test show operation with existing data
        $result = $queryBuilder->operation('show')->withId((string)$contactId)->get();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Show operation returns array format, not data wrapper
        $contactData = $result[0];
        $this->assertArrayHasKey('id', $contactData);
        $this->assertEquals($contactId, $contactData['id']);
        $this->assertEquals('John', $contactData['attributes']['first_name']); // snake_case
    }

    public function testShowOperationWithNonExistentData(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test show operation with non-existent ID
        $result = $queryBuilder->operation('show')->withId('999999')->get();
        $this->assertEquals([], $result);
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

    public function testInvalidIncludeValidation(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relationship: invalidRelation');

        $queryBuilder->include(['invalidRelation'])->operation('index')->get();
    }

    public function testUnsupportedOperationValidation(): void
    {
        // Create config with limited operations
        $restrictedConfig = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName'],
                'relationships' => [],
                'operations' => ['index' => true], // Only index allowed
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

    public function testInvalidOperationValidation(): void
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

    public function testComplexQueryWithMultipleIncludes(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        // Test complex query with multiple includes and filters
        $result = $queryBuilder
            ->include(['account'])
            ->filter(['firstName' => 'John'])
            ->fields(['firstName', 'email'])
            ->sort(['firstName'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        
        $contact = $result['data'][0];
        $this->assertNotNull($contact);
        $this->assertArrayHasKey('attributes', $contact);
        $this->assertEquals('John', $contact['attributes']['first_name']); // snake_case
        $this->assertArrayHasKey('relationships', $contact);
    }

    public function testUriGenerationWithComplexParameters(): void
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
            ->page(2, 5)
            ->buildUri();

        $this->assertIsString($uri);
        $this->assertStringContainsString('/contact', $uri);
        $this->assertStringContainsString('fields[contact]=firstName,email', $uri);
        $this->assertStringContainsString('include=account', $uri);
        $this->assertStringContainsString('page[number]=2', $uri);
        $this->assertStringContainsString('page[size]=5', $uri);
    }
}