<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\Filter\DateFilter;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class FilterCombinationIntegrationTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName', 'lastName', 'email', 'createdAt', 'updatedAt'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true],
            ],
        ];

        // Create test data with realistic variety for comprehensive filtering
        $account1 = new Account();
        $account1->setName('TechCorp Inc');
        $this->em->persist($account1);

        $account2 = new Account();
        $account2->setName('StartupXYZ Ltd');
        $this->em->persist($account2);

        // Tech contacts
        $contact1 = new Contact();
        $contact1->setFirstName('John');
        $contact1->setLastName('Smith');
        $contact1->setEmail('john.smith@techcorp.com');
        $contact1->setAccount($account1);
        $this->em->persist($contact1);

        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('Johnson');
        $contact2->setEmail('jane.johnson@techcorp.com');
        $contact2->setAccount($account1);
        $this->em->persist($contact2);

        // Startup contacts
        $contact3 = new Contact();
        $contact3->setFirstName('Alice');
        $contact3->setLastName('Williams');
        $contact3->setEmail('alice@startupxyz.io');
        $contact3->setAccount($account2);
        $this->em->persist($contact3);

        $contact4 = new Contact();
        $contact4->setFirstName('Bob');
        $contact4->setLastName('Brown');
        $contact4->setEmail('bob.brown@startupxyz.io');
        $contact4->setAccount($account2);
        $this->em->persist($contact4);

        // Freelancer contact (no specific company domain)
        $contact5 = new Contact();
        $contact5->setFirstName('Charlie');
        $contact5->setLastName('Davis');
        $contact5->setEmail('charlie.freelancer@gmail.com');
        $contact5->setAccount($account1);
        $this->em->persist($contact5);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testMultipleFilterTypesWorkTogether(): void
    {
        $registry = new FilterRegistry();
        
        // Register multiple filter types as would be done in real configuration
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::PARTIAL,
            'lastName' => SearchStrategy::PARTIAL,
            'email' => SearchStrategy::EXACT,
        ]);
        
        $dateFilter = new DateFilter(['createdAt', 'updatedAt']);
        
        $jsonApiFilterHandler = new JsonApiFilterHandler(['id']);

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $dateFilter);
        $registry->register(Contact::class, $jsonApiFilterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Combine text search + date range + numeric filter
        $result = $queryBuilder
            ->filter([
                'firstName' => 'J',  // SearchFilter: matches John, Jane
                'createdAt' => ['after' => '2020-01-01'],  // DateFilter: wide range
                'id' => ['gt' => 0]  // JsonApiFilterHandler: ID > 0
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']); // John and Jane match 'J' prefix

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testSearchAndDateFilterCombination(): void
    {
        $registry = new FilterRegistry();
        
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::END  // Search by email suffix
        ]);
        
        $dateFilter = new DateFilter(['createdAt']);

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Find startup contacts with wide date range
        $result = $queryBuilder
            ->filter([
                'email' => 'startupxyz.io',
                'createdAt' => ['after' => '2020-01-01']
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testComplexMultiFieldDateAndTextFiltering(): void
    {
        $registry = new FilterRegistry();
        
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::START,
            'lastName' => SearchStrategy::PARTIAL,
            'email' => SearchStrategy::PARTIAL
        ]);
        
        $dateFilter = new DateFilter(['createdAt', 'updatedAt']);

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Complex filtering: names starting with specific letters and company emails
        $result = $queryBuilder
            ->filter([
                'firstName' => 'J',  // John, Jane (starts with J)
                'email' => 'techcorp',  // company email
                'createdAt' => ['after' => '2020-01-01'],  // wide date range
                'updatedAt' => ['before' => '2030-01-01']   // wide date range
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']); // John and Jane match 'J' + 'techcorp' email

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testJsonApiFilterHandlerWithSearchFilter(): void
    {
        $registry = new FilterRegistry();
        
        $searchFilter = new SearchFilter([
            'firstName' => SearchStrategy::START
        ]);

        $jsonApiFilterHandler = new JsonApiFilterHandler(['lastName']); // Only handle lastName

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $jsonApiFilterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Combine text search with operators on different fields
        $result = $queryBuilder
            ->filter([
                'firstName' => 'A',  // Alice (SearchFilter)
                'lastName' => ['neq' => 'Davis']  // Not Davis (JsonApiFilterHandler)
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']); // Only Alice (starts with 'A', lastName != Davis)

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $lastNames = array_map(fn($contact) => $contact['attributes']['last_name'], $result['data']);
        $this->assertContains('Alice', $names);
        $this->assertNotContains('Davis', $lastNames); // Davis should be excluded
    }

    public function testSearchFilterOnEmailPartialMatch(): void
    {
        $registry = new FilterRegistry();

        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::PARTIAL
        ]);

        $registry->register(Contact::class, $searchFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Simple query: startup contacts
        $result = $queryBuilder
            ->filter([
                'email' => 'startupxyz'  // Should match startupxyz.io emails
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']); // Should get Alice and Bob

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testFilterPrecedenceAndOverlap(): void
    {
        $registry = new FilterRegistry();
        
        // Test when multiple filters might handle the same field
        $searchFilter = new SearchFilter([
            'email' => SearchStrategy::PARTIAL
        ]);
        
        // JsonApiFilterHandler handles different fields to avoid conflict
        $jsonApiFilterHandler = new JsonApiFilterHandler(['firstName', 'lastName']);

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $jsonApiFilterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // SearchFilter should apply PARTIAL search on email
        $result = $queryBuilder
            ->filter(['email' => 'techcorp'])  // Partial match
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);  // Should get both techcorp contacts

        $emails = array_map(fn($contact) => $contact['attributes']['email'], $result['data']);
        $this->assertContains('john.smith@techcorp.com', $emails);
        $this->assertContains('jane.johnson@techcorp.com', $emails);
    }

    public function testFilterRegistryManagesDifferentFilterTypes(): void
    {
        $registry = new FilterRegistry();
        
        $searchFilter = new SearchFilter(['firstName' => SearchStrategy::PARTIAL]);
        $dateFilter = new DateFilter(['createdAt']);
        $jsonApiFilterHandler = new JsonApiFilterHandler(['id']);

        $registry->register(Contact::class, $searchFilter);
        $registry->register(Contact::class, $dateFilter);
        $registry->register(Contact::class, $jsonApiFilterHandler);

        // Verify all filters are registered
        $this->assertTrue($registry->hasFilters(Contact::class));
        $filters = $registry->getFilters(Contact::class);
        $this->assertCount(3, $filters);

        // Verify filter types
        $filterTypes = array_map(fn($filter) => get_class($filter), $filters);
        $this->assertContains(SearchFilter::class, $filterTypes);
        $this->assertContains(DateFilter::class, $filterTypes);
        $this->assertContains(JsonApiFilterHandler::class, $filterTypes);
    }

    public function testNoFiltersRegisteredFallback(): void
    {
        // Test QueryBuilder behavior when no custom filters are registered
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
            // No FilterRegistry provided
        );

        $result = $queryBuilder->operation('index')->get();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(5, $result['data']); // All contacts returned
    }

    public function testEmptyFilterRegistryFallback(): void
    {
        $registry = new FilterRegistry();
        // No filters registered for Contact class

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        $result = $queryBuilder
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(5, $result['data']);
    }
}