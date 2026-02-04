<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\DateFilter;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class DateFilterIntegrationTest extends TestCase
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

        // Create test data with specific dates
        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);

        // Contact created 10 days ago
        $contact1 = new Contact();
        $contact1->setFirstName('John');
        $contact1->setLastName('Doe');
        $contact1->setEmail('john.doe@example.com');
        $contact1->setAccount($account);
        $this->em->persist($contact1);

        // Contact created 5 days ago  
        $contact2 = new Contact();
        $contact2->setFirstName('Jane');
        $contact2->setLastName('Smith');
        $contact2->setEmail('jane.smith@test.org');
        $contact2->setAccount($account);
        $this->em->persist($contact2);

        // Contact created yesterday
        $contact3 = new Contact();
        $contact3->setFirstName('Alice');
        $contact3->setLastName('Johnson');
        $contact3->setEmail('alice@company.com');
        $contact3->setAccount($account);
        $this->em->persist($contact3);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testDateFilterAfterOperator(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for contacts created after a very old date (should get all contacts)
        $result = $queryBuilder
            ->filter(['createdAt' => ['after' => '2020-01-01']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']); // All 3 contacts should match
    }

    public function testDateFilterBeforeOperator(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for contacts created before a future date (should get all contacts)
        $result = $queryBuilder
            ->filter(['createdAt' => ['before' => '2030-01-01']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']); // All contacts should match
    }

    public function testDateFilterStrictlyAfterOperator(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for contacts created strictly after current time (should get 0 contacts)
        $result = $queryBuilder
            ->filter(['createdAt' => ['strictly_after' => date('Y-m-d H:i:s')]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testDateFilterStrictlyBeforeOperator(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for contacts created strictly before a very old date (should get 0 contacts)  
        $result = $queryBuilder
            ->filter(['createdAt' => ['strictly_before' => '2020-01-01']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testDateFilterDateRange(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for contacts created in a wide range (should get all contacts)
        $result = $queryBuilder
            ->filter(['createdAt' => [
                'after' => '2020-01-01',
                'before' => '2030-01-01'
            ]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
    }

    public function testDateFilterMultipleFields(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt', 'updatedAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter by both createdAt and updatedAt in wide ranges
        $result = $queryBuilder
            ->filter([
                'createdAt' => ['after' => '2020-01-01'],
                'updatedAt' => ['before' => '2030-01-01']
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
    }

    public function testDateFilterIgnoresUndefinedProperties(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);  // Only createdAt defined
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter includes undefined updatedAt - should be ignored
        $result = $queryBuilder
            ->filter([
                'createdAt' => ['after' => '2020-01-01'], // Wide range to match all
                'updatedAt' => ['before' => '2021-01-01']  // Should be ignored
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']); // Only createdAt filter applied
    }

    public function testDateFilterIgnoresNonArrayValues(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Pass string instead of array - should be ignored
        $result = $queryBuilder
            ->filter(['createdAt' => '2026-01-29'])  // String instead of array
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']); // No filter applied
    }

    public function testDateFilterNoMatches(): void
    {
        $registry = new FilterRegistry();
        $dateFilter = new DateFilter(['createdAt']);
        $registry->register(Contact::class, $dateFilter);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Filter for future dates - no matches
        $result = $queryBuilder
            ->filter(['createdAt' => ['after' => '2026-02-10']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testDateFilterSupportsMethod(): void
    {
        $dateFilter = new DateFilter(['createdAt', 'updatedAt']);
        
        $this->assertTrue($dateFilter->supports('createdAt'));
        $this->assertTrue($dateFilter->supports('updatedAt'));
        $this->assertFalse($dateFilter->supports('firstName'));
        $this->assertFalse($dateFilter->supports('nonExistentField'));
    }

    public function testDateFilterGetDescription(): void
    {
        $dateFilter = new DateFilter(['createdAt', 'updatedAt']);
        $description = $dateFilter->getDescription();
        
        $this->assertIsArray($description);
        $this->assertEquals('DateFilter', $description['type']);
        $this->assertArrayHasKey('description', $description);
        $this->assertArrayHasKey('operators', $description);
        $this->assertArrayHasKey('properties', $description);
        $this->assertArrayHasKey('example', $description);
        
        $this->assertEquals(['createdAt', 'updatedAt'], $description['properties']);
        $this->assertArrayHasKey('after', $description['operators']);
        $this->assertArrayHasKey('before', $description['operators']);
        $this->assertArrayHasKey('strictly_after', $description['operators']);
        $this->assertArrayHasKey('strictly_before', $description['operators']);
    }

    public function testDateFilterDirectQueryBuilderApplication(): void
    {
        $dateFilter = new DateFilter(['createdAt']);
        
        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('*')->from('contacts', 't0');

        $fieldMappings = [
            'createdAt' => ['columnName' => 'created_at']
        ];

        $params = [
            'createdAt' => [
                'after' => '2026-01-29',
                'before' => '2026-02-01'
            ]
        ];

        $bindings = $dateFilter->apply($qb, $params, $fieldMappings);

        $this->assertIsArray($bindings);
        $this->assertCount(2, $bindings);
        
        $sql = $qb->getSQL();
        $this->assertStringContainsString('created_at >=', $sql);
        $this->assertStringContainsString('created_at <=', $sql);
        
        // Check bindings contain correct values
        $this->assertContains('2026-01-29', array_values($bindings));
        $this->assertContains('2026-02-01', array_values($bindings));
    }
}