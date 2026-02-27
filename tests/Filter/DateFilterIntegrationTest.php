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

        // Insert account via ORM to get a generated ID for the FK
        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);
        $this->em->flush();

        $now = date('Y-m-d H:i:s');
        $conn = $this->em->getConnection();

        $contacts = [
            [
                'first_name'  => 'John',
                'last_name'   => 'Doe',
                'email'       => 'john.doe@example.com',
                'account_id'  => $account->getId(),
                'created_at'  => date('Y-m-d H:i:s', strtotime('-10 days')),
                'updated_at'  => $now,
            ],
            [
                'first_name'  => 'Jane',
                'last_name'   => 'Smith',
                'email'       => 'jane.smith@test.org',
                'account_id'  => $account->getId(),
                'created_at'  => date('Y-m-d H:i:s', strtotime('-5 days')),
                'updated_at'  => $now,
            ],
            [
                'first_name'  => 'Alice',
                'last_name'   => 'Johnson',
                'email'       => 'alice@company.com',
                'account_id'  => $account->getId(),
                'created_at'  => date('Y-m-d H:i:s', strtotime('-1 day')),
                'updated_at'  => $now,
            ],
        ];

        foreach ($contacts as $contact) {
            $conn->insert('contacts', $contact);
        }
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

        // Boundary: after 7 days ago matches the -5d and -1d contacts (2 results)
        $result = $queryBuilder
            ->filter(['createdAt' => ['after' => date('Y-m-d H:i:s', strtotime('-7 days'))]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('attributes', $item);
        }
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

        // Boundary: before 7 days ago matches only the -10d contact (1 result)
        $result = $queryBuilder
            ->filter(['createdAt' => ['before' => date('Y-m-d H:i:s', strtotime('-7 days'))]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertArrayHasKey('id', $result['data'][0]);
        $this->assertArrayHasKey('attributes', $result['data'][0]);
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

        // Filter for contacts created strictly after a future timestamp (should get 0 contacts)
        $result = $queryBuilder
            ->filter(['createdAt' => ['strictly_after' => (new \DateTime('+1 year'))->format('Y-m-d H:i:s')]])
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

        // Boundary: range between -7 days and now matches the -5d and -1d contacts (2 results)
        $result = $queryBuilder
            ->filter(['createdAt' => [
                'after'  => date('Y-m-d H:i:s', strtotime('-7 days')),
                'before' => date('Y-m-d H:i:s'),
            ]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('attributes', $item);
        }
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

        // Both filters use wide ranges: all 3 contacts match
        $result = $queryBuilder
            ->filter([
                'createdAt' => ['after' => '2020-01-01'],
                'updatedAt' => ['before' => (new \DateTime('+1 year'))->format('Y-m-d H:i:s')],
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        foreach ($result['data'] as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('attributes', $item);
        }
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

        // updatedAt is not registered in the filter — it must be ignored, only createdAt applies
        $result = $queryBuilder
            ->filter([
                'createdAt' => ['after' => '2020-01-01'],
                'updatedAt' => ['before' => '2021-01-01'], // not registered, must be ignored
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']); // all 3 match the createdAt filter alone
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
        $futureDate = (new \DateTime('+1 year'))->format('Y-m-d');
        $result = $queryBuilder
            ->filter(['createdAt' => ['after' => $futureDate]])
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

        $after  = date('Y-m-d H:i:s', strtotime('-7 days'));
        $before = date('Y-m-d H:i:s');

        $params = [
            'createdAt' => [
                'after'  => $after,
                'before' => $before,
            ]
        ];

        $bindings = $dateFilter->apply($qb, $params, $fieldMappings);

        // Two operators applied → two bindings
        $this->assertIsArray($bindings);
        $this->assertCount(2, $bindings);

        // SQL must contain aliased column with correct operators
        $sql = $qb->getSQL();
        $this->assertStringContainsString('t0.created_at >=', $sql);
        $this->assertStringContainsString('t0.created_at <=', $sql);

        // Binding values must match what was passed in
        $values = array_values($bindings);
        $this->assertContains($after, $values);
        $this->assertContains($before, $values);
    }
}