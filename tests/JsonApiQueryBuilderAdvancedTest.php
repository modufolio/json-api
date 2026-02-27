<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiQueryParams;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

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

        foreach ([
            ['John', 'Doe', 'john@test.com'],
            ['Jane', 'Smith', 'jane@test.com'],
            ['Alice', 'Brown', 'alice@test.com'],
        ] as [$first, $last, $email]) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName($last);
            $contact->setEmail($email);
            $contact->setAccount($account);
            $this->em->persist($contact);
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function makeQb(string $class = Contact::class): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $class
        );
    }

    public function testIncludeWithManyToOneRelationship(): void
    {
        $result = $this->makeQb()->include(['account'])->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        $this->assertArrayHasKey('id', $result['data'][0]);
        $this->assertArrayHasKey('attributes', $result['data'][0]);
    }

    public function testIncludeWithOneToManyRelationship(): void
    {
        $result = $this->makeQb(Account::class)->include(['contacts'])->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data']);
        $this->assertArrayHasKey('id', $result['data'][0]);
        $this->assertArrayHasKey('attributes', $result['data'][0]);
    }

    public function testIncludeWithUnknownAssociationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid relationship: nonexistent');

        $this->makeQb(Account::class)->include(['nonexistent'])->operation('index')->get();
    }

    public function testBasicIndexOperation(): void
    {
        $result = $this->makeQb()->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
    }

    public function testFilterByExactValue(): void
    {
        $result = $this->makeQb()->filter(['firstName' => 'John'])->operation('index')->get();

        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testFilterWithInOperator(): void
    {
        $result = $this->makeQb()
            ->filter(['firstName' => ['in' => ['John', 'Jane']]])
            ->operation('index')
            ->get();

        $this->assertCount(2, $result['data']);
        $names = array_column(array_column($result['data'], 'attributes'), 'first_name');
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testShow(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $result = $this->makeQb()->operation('show')->withId((string) $contact->getId())->get();

        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['attributes']['first_name']);
        $this->assertEquals((string) $contact->getId(), $result[0]['id']);
    }

    public function testShowNotFound(): void
    {
        $result = $this->makeQb()->operation('show')->withId('99999')->get();

        $this->assertEquals([], $result);
    }

    public function testShowWithNoIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ID required for show operation');

        $this->makeQb()->operation('show')->get();
    }

    public function testCreate(): void
    {
        $result = $this->makeQb(Account::class)
            ->withData(['name' => 'New Account'])
            ->operation('create')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('New Account', $result[0]['attributes']['name']);
        $this->assertNotEmpty($result[0]['id']);
    }

    public function testUpdate(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $result = $this->makeQb()
            ->withId((string) $contact->getId())
            ->withData(['firstName' => 'Johnny'])
            ->operation('update')
            ->get();

        $this->assertCount(1, $result);
        $this->assertEquals('Johnny', $result[0]['attributes']['first_name']);
    }

    public function testUpdateWithNoIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ID required for update operation');

        $this->makeQb()->withData(['firstName' => 'X'])->operation('update')->get();
    }

    public function testDelete(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);
        $id = (string) $contact->getId();

        $result = $this->makeQb()->withId($id)->operation('delete')->get();

        $this->assertEquals(['status' => 'deleted', 'id' => $id], $result);
        $this->assertCount(2, $this->makeQb()->operation('index')->get()['data']);
    }

    public function testDeleteWithNoIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ID required for delete operation');

        $this->makeQb()->operation('delete')->get();
    }

    public function testCount(): void
    {
        $this->assertEquals(3, $this->makeQb()->count());
    }

    public function testCountWithFilter(): void
    {
        $this->assertEquals(1, $this->makeQb()->filter(['firstName' => 'John'])->count());
    }

    public function testMax(): void
    {
        $this->assertGreaterThanOrEqual(3, $this->makeQb()->max('id'));
    }

    public function testMin(): void
    {
        $min = $this->makeQb()->min('id');
        $this->assertGreaterThanOrEqual(1, $min);
        $this->assertLessThanOrEqual($this->makeQb()->max('id'), $min);
    }

    public function testSum(): void
    {
        $this->assertGreaterThan(0, $this->makeQb()->sum('id'));
    }

    public function testAvg(): void
    {
        $this->assertGreaterThan(0, $this->makeQb()->avg('id'));
    }

    public function testAggregateWithInvalidFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeQb()->max('nonExistentField');
    }

    public function testWithTotalCount(): void
    {
        $result = $this->makeQb()->withTotalCount()->operation('index')->get();

        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(3, $result['total']);
        $this->assertCount(3, $result['data']);
    }

    public function testWithTotalCountAndFilter(): void
    {
        $result = $this->makeQb()->withTotalCount()->filter(['firstName' => 'John'])->operation('index')->get();

        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['data']);
    }

    public function testDebugIndex(): void
    {
        $result = $this->makeQb()->debug()->filter(['firstName' => 'John'])->operation('index')->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('bindings', $result);
        $this->assertStringContainsString('SELECT', $result['query']);
    }

    public function testDebugShow(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $result = $this->makeQb()->debug()->operation('show')->withId((string) $contact->getId())->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertStringContainsString('SELECT', $result['query']);
    }

    public function testDebugCreate(): void
    {
        $result = $this->makeQb()->debug()
            ->withData(['firstName' => 'Debug', 'lastName' => 'User', 'email' => 'debug@test.com'])
            ->operation('create')
            ->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertStringContainsString('INSERT', $result['query']);
    }

    public function testDebugUpdate(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $result = $this->makeQb()->debug()
            ->withId((string) $contact->getId())
            ->withData(['firstName' => 'Updated'])
            ->operation('update')
            ->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertStringContainsString('UPDATE', $result['query']);
    }

    public function testDebugDelete(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $result = $this->makeQb()->debug()->withId((string) $contact->getId())->operation('delete')->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertStringContainsString('DELETE', $result['query']);
    }

    public function testToSql(): void
    {
        $sql = $this->makeQb()->filter(['firstName' => 'John'])->toSql();

        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('contacts', $sql);
    }

    public function testGetQueryBuilder(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, $this->makeQb()->getQueryBuilder());
    }

    public function testExpr(): void
    {
        $this->assertInstanceOf(ExpressionBuilder::class, $this->makeQb()->expr());
    }

    public function testApplyParamsFilter(): void
    {
        $params = new JsonApiQueryParams();
        $params->filter = ['firstName' => 'Jane'];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertCount(1, $result['data']);
        $this->assertEquals('Jane', $result['data'][0]['attributes']['first_name']);
    }

    public function testApplyParamsSort(): void
    {
        $params = new JsonApiQueryParams();
        $params->sort = ['-firstName'];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertCount(3, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testApplyParamsFields(): void
    {
        $params = new JsonApiQueryParams();
        $params->fields = ['firstName'];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertArrayHasKey('first_name', $result['data'][0]['attributes']);
        $this->assertArrayNotHasKey('last_name', $result['data'][0]['attributes']);
    }

    public function testApplyParamsPage(): void
    {
        $params = new JsonApiQueryParams();
        $params->page = ['number' => 1, 'size' => 2];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertCount(2, $result['data']);
    }

    public function testApplyParamsInclude(): void
    {
        $params = new JsonApiQueryParams();
        $params->include = ['account'];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertCount(3, $result['data']);
    }

    public function testApplyParamsGroup(): void
    {
        $params = new JsonApiQueryParams();
        $params->group = ['firstName'];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
    }

    public function testApplyParamsHaving(): void
    {
        $params = new JsonApiQueryParams();
        $params->group = ['firstName'];
        $params->having = ['query' => 'COUNT(*) >= 1', 'bindings' => []];

        $result = $this->makeQb()->applyParams($params)->operation('index')->get();

        $this->assertArrayHasKey('data', $result);
    }

    public function testApplyParamsId(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'john@test.com']);

        $params = new JsonApiQueryParams();
        $params->id = (string) $contact->getId();

        $result = $this->makeQb()->applyParams($params)->operation('show')->get();

        $this->assertCount(1, $result);
        $this->assertEquals('John', $result[0]['attributes']['first_name']);
    }

    public function testBuildUri(): void
    {
        $uri = $this->makeQb()
            ->fields(['firstName', 'email'])
            ->filter(['firstName' => ['like' => '%John%']])
            ->include(['account'])
            ->sort(['-firstName', 'email'])
            ->page(2, 10)
            ->buildUri();

        $this->assertStringContainsString('/contact', $uri);
        $this->assertStringContainsString('page[number]=2', $uri);
        $this->assertStringContainsString('page[size]=10', $uri);
    }

    public function testBuildUriWithNullFilter(): void
    {
        $uri = $this->makeQb()->filter(['email' => ['null' => true]])->buildUri();

        $this->assertStringContainsString('filter[email][null]', $uri);
    }

    public function testBuildUriWithGroupAndHaving(): void
    {
        $uri = $this->makeQb()->group('firstName')->having('COUNT(*) >= 1')->buildUri();

        $this->assertStringContainsString('group=', $uri);
        $this->assertStringContainsString('having=', $uri);
    }

    public function testInvalidFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fields: invalidField');

        $this->makeQb()->filter(['invalidField' => 'test'])->operation('index')->get();
    }

    public function testUnsupportedOperationThrows(): void
    {
        $restrictedConfig = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName'],
                'relationships' => [],
                'operations' => ['index' => true],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation create not supported');

        (new JsonApiQueryBuilder($restrictedConfig, $this->em, $this->em->getConnection(), Contact::class))
            ->operation('create');
    }

    public function testInvalidOperationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Operation invalidOp not supported');

        $this->makeQb()->operation('invalidOp');
    }

    public function testInClauseOverLimitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN clause contains too many values');

        $this->makeQb()->filter(['firstName' => ['in' => array_fill(0, 1001, 'x')]])->operation('index')->get();
    }

    public function testHavingWithDangerousSqlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HAVING condition');

        $this->makeQb()->group('firstName')->having('1=1; DROP TABLE contacts')->operation('index')->get();
    }

    public function testHavingWithUnionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HAVING condition');

        $this->makeQb()->group('firstName')->having('COUNT(*) >= 1 UNION SELECT * FROM contacts')->operation('index')->get();
    }

    public function testLikePatternTooLongThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('LIKE pattern too long');

        $this->makeQb()->filter(['firstName' => ['like' => str_repeat('a', 256)]])->operation('index')->get();
    }

    public function testSqlKeywordAsColumnNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeQb()->filter(['SELECT' => 'value'])->operation('index')->get();
    }

    public function testAssociativeRelationshipsConfig(): void
    {
        $config = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account' => ['resource_key' => 'account']],
                'operations' => ['index' => true],
            ],
            Account::class => $this->config[Account::class],
        ];

        $result = (new JsonApiQueryBuilder($config, $this->em, $this->em->getConnection(), Contact::class))
            ->operation('index')
            ->get();

        $this->assertCount(3, $result['data']);
    }
}
