<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JsonApiFilterHandlerIntegrationTest extends TestCase
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

        // Create test data with specific values for operator testing
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
        $contact4->setLastName(null); // For null testing
        $contact4->setEmail('bob@service.net');
        $contact4->setAccount($account);
        $this->em->persist($contact4);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testJsonApiFilterHandlerSimpleEquality(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test simple equality filter
        $result = $queryBuilder
            ->filter(['firstName' => 'John'])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testJsonApiFilterHandlerNotEqualOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test not equal operator
        $result = $queryBuilder
            ->filter(['firstName' => ['neq' => 'John']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        
        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertNotContains('John', $names);
        $this->assertContains('Jane', $names);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testJsonApiFilterHandlerNotOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test 'not' operator (alias for neq)
        $result = $queryBuilder
            ->filter(['firstName' => ['not' => 'Alice']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        
        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertNotContains('Alice', $names);
    }

    public function testJsonApiFilterHandlerGreaterThanOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Get all contacts first to determine actual IDs
        $allResult = $queryBuilder->operation('index')->get();
        $allIds = array_map(fn($contact) => (int)$contact['id'], $allResult['data']);
        sort($allIds);
        $midId = $allIds[1]; // Use second smallest ID

        // Test greater than operator on ID
        $result = $queryBuilder
            ->filter(['id' => ['gt' => $midId]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, count($result['data']));

        $ids = array_map(fn($contact) => (int)$contact['id'], $result['data']);
        foreach ($ids as $id) {
            $this->assertGreaterThan($midId, $id);
        }
    }

    public function testJsonApiFilterHandlerGreaterThanOrEqualOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Get all contacts first to determine actual IDs
        $allResult = $queryBuilder->operation('index')->get();
        $allIds = array_map(fn($contact) => (int)$contact['id'], $allResult['data']);
        sort($allIds);
        $midId = $allIds[1]; // Use second smallest ID

        // Test greater than or equal operator
        $result = $queryBuilder
            ->filter(['id' => ['gte' => $midId]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, count($result['data']));

        $ids = array_map(fn($contact) => (int)$contact['id'], $result['data']);
        foreach ($ids as $id) {
            $this->assertGreaterThanOrEqual($midId, $id);
        }
    }

    public function testJsonApiFilterHandlerLessThanOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Get all contacts first to determine actual IDs
        $allResult = $queryBuilder->operation('index')->get();
        $allIds = array_map(fn($contact) => (int)$contact['id'], $allResult['data']);
        sort($allIds);
        $maxId = max($allIds);

        // Test less than operator
        $result = $queryBuilder
            ->filter(['id' => ['lt' => $maxId]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, count($result['data']));

        $ids = array_map(fn($contact) => (int)$contact['id'], $result['data']);
        foreach ($ids as $id) {
            $this->assertLessThan($maxId, $id);
        }
    }

    public function testJsonApiFilterHandlerLessThanOrEqualOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Get all contacts first to determine actual IDs
        $allResult = $queryBuilder->operation('index')->get();
        $allIds = array_map(fn($contact) => (int)$contact['id'], $allResult['data']);
        sort($allIds);
        $secondId = $allIds[1]; // Use second smallest ID

        // Test less than or equal operator
        $result = $queryBuilder
            ->filter(['id' => ['lte' => $secondId]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThan(0, count($result['data']));

        $ids = array_map(fn($contact) => (int)$contact['id'], $result['data']);
        foreach ($ids as $id) {
            $this->assertLessThanOrEqual($secondId, $id);
        }
    }

    public function testJsonApiFilterHandlerLikeOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test LIKE operator
        $result = $queryBuilder
            ->filter(['email' => ['like' => '%@example.com']])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testJsonApiFilterHandlerInOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test IN operator
        $result = $queryBuilder
            ->filter(['firstName' => ['in' => ['John', 'Alice']]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('John', $names);
        $this->assertContains('Alice', $names);
    }

    public function testJsonApiFilterHandlerEmptyInOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test empty IN operator (should return no results)
        $result = $queryBuilder
            ->filter(['firstName' => ['in' => []]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testJsonApiFilterHandlerNullOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test IS NULL operator
        $result = $queryBuilder
            ->filter(['lastName' => ['null' => true]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('Bob', $result['data'][0]['attributes']['first_name']);
    }

    public function testJsonApiFilterHandlerNotNullOperator(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test IS NOT NULL operator
        $result = $queryBuilder
            ->filter(['lastName' => ['not_null' => true]])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertNotContains('Bob', $names);
    }

    public function testJsonApiFilterHandlerAllowedFields(): void
    {
        $registry = new FilterRegistry();
        // Create handler with only specific allowed fields
        $filterHandler = new JsonApiFilterHandler(['firstName', 'email']);
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Test filtering on allowed field
        $result = $queryBuilder
            ->filter(['firstName' => 'John'])
            ->operation('index')
            ->get();

        $this->assertCount(1, $result['data']);

        // Test filtering on disallowed field (should be ignored)
        $result = $queryBuilder
            ->filter(['lastName' => 'Doe'])
            ->operation('index')
            ->get();

        $this->assertCount(4, $result['data']); // No filter applied
    }

    public function testJsonApiFilterHandlerSupportsMethod(): void
    {
        $filterHandler = new JsonApiFilterHandler(['firstName', 'email']);
        
        $this->assertTrue($filterHandler->supports('firstName'));
        $this->assertTrue($filterHandler->supports('email'));
        $this->assertFalse($filterHandler->supports('lastName'));
        $this->assertFalse($filterHandler->supports('nonExistentField'));
    }

    public function testJsonApiFilterHandlerSupportsAllFieldsWhenEmpty(): void
    {
        $filterHandler = new JsonApiFilterHandler(); // No specific fields
        
        $this->assertTrue($filterHandler->supports('firstName'));
        $this->assertTrue($filterHandler->supports('lastName'));
        $this->assertTrue($filterHandler->supports('anyField'));
    }

    public function testJsonApiFilterHandlerGetSupportedOperators(): void
    {
        $filterHandler = new JsonApiFilterHandler();
        $operators = $filterHandler->getSupportedOperators();
        
        $expectedOperators = ['eq', 'neq', 'not', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'null', 'not_null'];
        $this->assertEquals($expectedOperators, $operators);
    }

    public function testJsonApiFilterHandlerSupportsOperator(): void
    {
        $filterHandler = new JsonApiFilterHandler();
        
        $this->assertTrue($filterHandler->supportsOperator('eq'));
        $this->assertTrue($filterHandler->supportsOperator('gt'));
        $this->assertTrue($filterHandler->supportsOperator('like'));
        $this->assertTrue($filterHandler->supportsOperator('in'));
        $this->assertFalse($filterHandler->supportsOperator('nonExistentOperator'));
    }

    public function testJsonApiFilterHandlerGetDescription(): void
    {
        $filterHandler = new JsonApiFilterHandler(['firstName', 'email']);
        $description = $filterHandler->getDescription();
        
        $this->assertIsArray($description);
        $this->assertEquals('JsonApiFilterHandler', $description['type']);
        $this->assertArrayHasKey('description', $description);
        $this->assertArrayHasKey('operators', $description);
        $this->assertArrayHasKey('fields', $description);
        
        $this->assertEquals(['firstName', 'email'], $description['fields']);
        $this->assertContains('eq', $description['operators']);
        $this->assertContains('gt', $description['operators']);
        $this->assertContains('like', $description['operators']);
    }

    public function testJsonApiFilterHandlerGetDescriptionAllFields(): void
    {
        $filterHandler = new JsonApiFilterHandler(); // No specific fields
        $description = $filterHandler->getDescription();
        
        $this->assertEquals(['all'], $description['fields']);
    }

    public function testJsonApiFilterHandlerUnsupportedOperatorThrowsException(): void
    {
        $filterHandler = new JsonApiFilterHandler();
        
        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('*')->from('contacts', 't0');

        $fieldMappings = [
            'firstName' => ['columnName' => 'first_name']
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported filter operator on first_name');

        $filterHandler->apply($qb, ['firstName' => ['unsupported' => 'value']], $fieldMappings);
    }

    public function testJsonApiFilterHandlerIgnoresNonStringFields(): void
    {
        $filterHandler = new JsonApiFilterHandler();
        
        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('*')->from('contacts', 't0');

        $fieldMappings = [
            'firstName' => ['columnName' => 'first_name']
        ];

        // Filter with numeric key should be ignored
        $bindings = $filterHandler->apply($qb, [0 => 'invalidFilter', 'firstName' => 'John'], $fieldMappings);

        $this->assertCount(1, $bindings); // Only 'firstName' filter applied
        // The parameter name depends on the iteration order, so check that one binding exists with value 'John'
        $this->assertContains('John', $bindings);
    }

    public function testJsonApiFilterHandlerMultipleFilters(): void
    {
        $registry = new FilterRegistry();
        $filterHandler = new JsonApiFilterHandler();
        $registry->register(Contact::class, $filterHandler);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        $allResult = $queryBuilder->operation('index')->get();
        $allIds = array_map(fn($c) => (int)$c['id'], $allResult['data']);
        sort($allIds);
        $secondId = $allIds[1]; // skip the first contact (John)

        $result = $queryBuilder
            ->filter([
                'id'        => ['gte' => $secondId],
                'firstName' => ['neq' => 'Bob'],
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);

        $names = array_map(fn($contact) => $contact['attributes']['first_name'], $result['data']);
        $this->assertContains('Jane', $names);
        $this->assertContains('Alice', $names);
        $this->assertNotContains('John', $names);
        $this->assertNotContains('Bob', $names);
    }
}