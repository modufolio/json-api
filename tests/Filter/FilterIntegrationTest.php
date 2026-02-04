<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\Filter\DateFilter;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class FilterIntegrationTest extends TestCase
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

        // Create test data with varied contact information
        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);

        $contact1 = new Contact();
        $contact1->setFirstName('John');
        $contact1->setLastName('Doe');
        $contact1->setEmail('john.doe@example.com');
        $contact1->setAccount($account);
        $this->em->persist($contact1);

        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('Smith');
        $contact2->setEmail('jane.smith@test.org');
        $contact2->setAccount($account);
        $this->em->persist($contact2);

        $contact3 = new Contact();
        $contact3->setFirstName('Alice');
        $contact3->setLastName('Johnson');
        $contact3->setEmail('alice@company.com');
        $contact3->setAccount($account);
        $this->em->persist($contact3);

        $contact4 = new Contact();
        $contact4->setFirstName('Bob');
        $contact4->setLastName('Brown');
        $contact4->setEmail('bob.brown@service.net');
        $contact4->setAccount($account);
        $this->em->persist($contact4);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testFilterRegistryBasicOperations(): void
    {
        $registry = new FilterRegistry();
        
        // Test empty registry
        $this->assertFalse($registry->hasFilters(Contact::class));
        $this->assertEmpty($registry->getFilters(Contact::class));

        // Register a filter
        $searchFilter = new SearchFilter(['firstName' => SearchStrategy::PARTIAL]);
        $registry->register(Contact::class, $searchFilter);

        // Test registry with filter
        $this->assertTrue($registry->hasFilters(Contact::class));
        $filters = $registry->getFilters(Contact::class);
        $this->assertCount(1, $filters);
        $this->assertInstanceOf(SearchFilter::class, $filters[0]);
    }

    public function testFilterRegistryMultipleFilters(): void
    {
        $registry = new FilterRegistry();
        
        $searchFilter = new SearchFilter(['firstName' => SearchStrategy::PARTIAL]);
        $emailFilter = new SearchFilter(['email' => SearchStrategy::EXACT]);
        
        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $emailFilter);

        $filters = $registry->getFilters(Contact::class);
        $this->assertCount(2, $filters);
    }

    public function testSearchFilterPartialStrategy(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::PARTIAL,
            'lastName' => SearchStrategy::PARTIAL
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test partial match on firstName
        $result = $queryBuilder
            ->filter(['firstName' => 'Jo'])  // Should match John
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testSearchFilterExactStrategy(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::EXACT
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test exact match on email
        $result = $queryBuilder
            ->filter(['email' => 'jane.smith@test.org'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('Jane', $result['data'][0]['attributes']['first_name']);
    }

    public function testSearchFilterStartStrategy(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::START
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test start match on email (emails starting with 'bob')
        $result = $queryBuilder
            ->filter(['email' => 'bob'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('Bob', $result['data'][0]['attributes']['first_name']);
    }

    public function testSearchFilterEndStrategy(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::END
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test end match on email (emails ending with 'example.com')
        $result = $queryBuilder
            ->filter(['email' => 'example.com'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testSearchFilterMultipleFields(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::PARTIAL,
            'lastName' => SearchStrategy::PARTIAL
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test filtering by multiple fields
        $result = $queryBuilder
            ->filter([
                'firstName' => 'Jane',
                'lastName' => 'Smith'
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('Jane', $result['data'][0]['attributes']['first_name']);
        $this->assertEquals('Smith', $result['data'][0]['attributes']['last_name']);
    }

    public function testSearchFilterNoMatches(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::EXACT
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test filter with no matches
        $result = $queryBuilder
            ->filter(['firstName' => 'NonExistentName'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testSearchFilterIgnoresUndefinedProperties(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::PARTIAL
            // lastName not defined in filter
        ]);
        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter includes undefined property - should be ignored
        $result = $queryBuilder
            ->filter([
                'firstName' => 'John',
                'lastName' => 'Smith'  // Not defined in filter, should be ignored
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testFilterRegistryWithMultipleResourceClasses(): void
    {
        $registry = new FilterRegistry();
        
        $contactFilter = new SearchFilter(['firstName' => SearchStrategy::PARTIAL]);
        $accountFilter = new SearchFilter(['name' => SearchStrategy::EXACT]);
        
        $registry->register(Contact::class, $contactFilter);
        $registry->register(Account::class, $accountFilter);

        // Test filters for Contact
        $this->assertTrue($registry->hasFilters(Contact::class));
        $contactFilters = $registry->getFilters(Contact::class);
        $this->assertCount(1, $contactFilters);

        // Test filters for Account
        $this->assertTrue($registry->hasFilters(Account::class));
        $accountFilters = $registry->getFilters(Account::class);
        $this->assertCount(1, $accountFilters);

        // Test no filters for unregistered class
        $this->assertFalse($registry->hasFilters('UnregisteredClass'));
        $this->assertEmpty($registry->getFilters('UnregisteredClass'));
    }

    public function testQueryBuilderWithoutFilterRegistry(): void
    {
        // Test QueryBuilder without FilterRegistry (fallback behavior)
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
            // No FilterRegistry passed
        );

        // Should still work with basic filtering
        $result = $queryBuilder->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(4, $result['data']); // All contacts
    }

    public function testFilterRegistryApplyFilters(): void
    {
        $registry = new FilterRegistry();
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::PARTIAL
        ]);
        $registry->register(Contact::class, $searchFilter);

        // Test applyFilters method directly
        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('*')->from('contacts', 't0');

        $fieldMappings = [
            'email' => ['columnName' => 'email']
        ];

        $bindings = $registry->applyFilters(
            Contact::class,
            $qb,
            ['email' => 'test'],
            $fieldMappings
        );

        $this->assertIsArray($bindings);
        $this->assertNotEmpty($bindings);

        // Check that the query was modified
        $sql = $qb->getSQL();
        $this->assertStringContainsString('email', $sql);
        $this->assertStringContainsString('LIKE', $sql);
    }
}