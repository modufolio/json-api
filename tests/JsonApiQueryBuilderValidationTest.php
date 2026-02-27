<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

class JsonApiQueryBuilderValidationTest extends TestCase
{
    private EntityManager $em;
    private JsonApiQueryBuilder $queryBuilder;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Account::class => [
                'resource_key' => 'account',
                'fields' => ['id', 'name'],
                'relationships' => ['organizations', 'contacts'],
                'operations' => [
                    'index' => true,
                    'show' => true,
                    'create' => false,
                ],
            ],
        ];

        $this->queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    public function testInvalidFieldsThrowException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalid_field, another_invalid');

        $this->queryBuilder->fields(['name', 'invalid_field', 'another_invalid']);
    }

    public function testInvalidFilterFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalid_field');

        $this->queryBuilder->filter(['invalid_field' => 'value']);
    }

    public function testInvalidSortFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalid_field');

        $this->queryBuilder->sort(['invalid_field']);
    }

    public function testInvalidRelationshipThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relationship: invalid_relationship');

        $this->queryBuilder->include(['invalid_relationship']);
    }

    public function testDisabledOperationThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation create not supported');

        $this->queryBuilder->operation('create');
    }

    public function testGroupWithInvalidFieldThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalid_field');

        $this->queryBuilder->group('invalid_field');
    }

    public function testUnsupportedFilterOperatorThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported filter operator for column: name');

        $this->queryBuilder->filter(['name' => ['unsupported_operator' => 'value']])->operation('index')->get();
    }

    public function testFilterWithNullOperator(): void
    {
        $result = $this->queryBuilder->filter(['name' => ['null' => true]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithNotNullOperator(): void
    {
        $result = $this->queryBuilder->filter(['name' => ['not_null' => true]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithNotOperator(): void
    {
        $result = $this->queryBuilder->filter(['name' => ['not' => 'NonExistent']])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithNeqOperator(): void
    {
        $result = $this->queryBuilder->filter(['name' => ['neq' => 'NonExistent']])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithGtOperator(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['gt' => 0]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithGteOperator(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['gte' => 1]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithLtOperator(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['lt' => 999]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithLteOperator(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['lte' => 999]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithLikeOperator(): void
    {
        $result = $this->queryBuilder->filter(['name' => ['like' => '%test%']])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithInOperator(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['in' => [1, 2, 3]]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testFilterWithEmptyInOperatorReturnsNoResults(): void
    {
        $result = $this->queryBuilder->filter(['id' => ['in' => []]])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(0, $result['data']);
    }

    public function testEmptyFieldsArray(): void
    {
        $result = $this->queryBuilder->fields([])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testEmptyFiltersArray(): void
    {
        $result = $this->queryBuilder->filter([])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testEmptySortArray(): void
    {
        $result = $this->queryBuilder->sort([])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testEmptyIncludeArray(): void
    {
        $result = $this->queryBuilder->include([])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testSparseFieldsetsWithInvalidResourceType(): void
    {
        $result = $this->queryBuilder->fields(['other_resource' => ['name']])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testSparseFieldsetsWithValidResourceType(): void
    {
        $result = $this->queryBuilder->fields(['account' => ['name']])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testConstructorWithFilterRegistry(): void
    {
        $filterRegistry = new FilterRegistry();

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class,
            $filterRegistry
        );

        $this->assertInstanceOf(JsonApiQueryBuilder::class, $queryBuilder);
    }

    public function testFilterRegistryWithFilters(): void
    {
        $filterRegistry = new FilterRegistry();
        $filterRegistry->register(Account::class, new SearchFilter(['name' => SearchStrategy::PARTIAL]));

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Account::class,
            $filterRegistry
        );

        $result = $queryBuilder->filter(['name' => 'test'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testConfigWithoutOperationsDefaultsToIndexOnly(): void
    {
        $configWithoutOps = [
            Account::class => [
                'resource_key' => 'account',
                'fields' => ['id', 'name'],
                'relationships' => [],
            ],
        ];

        $qb = new JsonApiQueryBuilder(
            $configWithoutOps,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        $result = $qb->operation('index')->get();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation show not supported');
        $qb->operation('show');
    }

    public function testConfigWithoutFieldsUsesMetadataFields(): void
    {
        $configWithoutFields = [
            Account::class => [
                'resource_key' => 'account',
                'relationships' => [],
                'operations' => ['index' => true],
            ],
        ];

        $qb = new JsonApiQueryBuilder(
            $configWithoutFields,
            $this->em,
            $this->em->getConnection(),
            Account::class
        );

        $result = $qb->fields(['id', 'name'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    public function testNestedRelationshipPath(): void
    {
        $this->queryBuilder->include(['organizations.contacts']);
        $this->addToAssertionCount(1);
    }

    public function testMixedSortFormats(): void
    {
        $result = $this->queryBuilder->sort(['name' => 'ASC', '-id'])->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }
}
