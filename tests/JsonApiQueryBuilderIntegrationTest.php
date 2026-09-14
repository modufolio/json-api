<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderIntegrationTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
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
        ];

        $account = new Account();
        $account->setName('Test Account');
        $this->em->persist($account);

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

    public function testUpdateOperation(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['firstName' => 'John']);
        $this->assertNotNull($contact);

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder
            ->operation('update')
            ->withId((string)$contact->getId())
            ->withData(['firstName' => 'Johnny', 'email' => 'johnny@test.com'])
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertArrayHasKey('id', $result['data']);
        $this->assertArrayHasKey('attributes', $result['data']);
        $this->assertEquals('Johnny', $result['data']['attributes']['first_name']);
        $this->assertEquals('johnny@test.com', $result['data']['attributes']['email']);
    }

    public function testDeleteOperation(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['firstName' => 'John']);
        $this->assertNotNull($contact);
        $contactId = $contact->getId();

        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder
            ->operation('delete')
            ->withId((string)$contactId)
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('deleted', $result['status']);
        $this->assertEquals((string)$contactId, $result['id']);
    }

    public function testComplexFiltering(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $result = $queryBuilder
            ->filter([
                'firstName' => ['neq' => 'NotJohn'],
                'email'     => ['not_null' => true],
            ])
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
        $this->assertEquals('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testGroupAndHaving(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $this->expectException(QueryParamMalformed::class);

        $queryBuilder
            ->group('firstName')
            ->having('COUNT(*) >= 1')
            ->operation('index')
            ->get();
    }

    public function testTransformRowWithRelationships(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $reflection = new \ReflectionClass($queryBuilder);
        $method = $reflection->getMethod('transformRowToJsonApi');
        $method->setAccessible(true);

        $row = [
            'id'              => 1,
            'first_name'      => 'John',
            'email'           => 'john@test.com',
            '_rel_account_id' => 5,
        ];

        $result = $method->invoke($queryBuilder, $row);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('account', $result['relationships']);
        $this->assertEquals('5', $result['relationships']['account']['data']['id']);
        $this->assertEquals('account', $result['relationships']['account']['data']['type']);
    }

    public function testBuildUriWithNullHaving(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $uri = $queryBuilder
            ->fields(['firstName', 'email'])
            ->sort(['firstName'])
            ->buildUri();

        $this->assertStringContainsString('/contact', $uri);
        $this->assertStringContainsString('fields[contact]=firstName,email', $uri);
        $this->assertStringContainsString('sort=firstName', $uri);
        $this->assertStringNotContainsString('having', $uri);
    }

    public function testSparseFieldsetsEdgeCase(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $queryBuilder->fields(['other_resource' => []]);

        $result = $queryBuilder->operation('index')->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertCount(1, $result['data']);
    }

    public function testToSqlMethod(): void
    {
        $queryBuilder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );

        $sql = $queryBuilder
            ->filter(['firstName' => 'John'])
            ->sort(['email'])
            ->toSql();

        $this->assertIsString($sql);
        $this->assertStringContainsString('SELECT', $sql);
        $this->assertStringContainsString('first_name', $sql);
        $this->assertStringContainsString('email', $sql);
    }

    // ── To-one relationships on create and update ───────────────────────────

    /**
     * A to-one whose foreign key lives on the row is written through its
     * join column, from the `['relationship' => id]` shape the deserializer
     * produces. Null clears it.
     */
    public function testCreateAndUpdateWriteAToOneRelationship(): void
    {
        $account = $this->em->getRepository(Account::class)->findOneBy(['name' => 'Test Account']);
        $this->assertNotNull($account);
        $other = new Account();
        $other->setName('Other');
        $this->em->persist($other);
        $this->em->flush();

        $config = $this->config;
        $config[Contact::class]['relationships'] = ['account', 'organization'];
        $builder = fn () => new JsonApiQueryBuilder($config, $this->em, $this->em->getConnection(), Contact::class);

        $created = $builder()
            ->withData(['firstName' => 'New', 'lastName' => 'One', 'email' => 'new@test.com', 'account' => $account->getId()])
            ->operation('create')
            ->get();

        $id = (string) $created['data']['id'];
        $this->assertSame((string) $account->getId(), $created['data']['relationships']['account']['data']['id']);
        $this->assertSame(['data' => null], $created['data']['relationships']['organization']);

        $updated = $builder()->withId($id)->withData(['account' => $other->getId()])->operation('update')->get();
        $this->assertSame((string) $other->getId(), $updated['data']['relationships']['account']['data']['id']);
    }

    public function testAToManyInWriteDataIsRefused(): void
    {
        $config = $this->config;
        $config[Contact::class]['relationships'] = ['account', 'tags'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Relationship 'tags' is not written through this resource");

        (new JsonApiQueryBuilder($config, $this->em, $this->em->getConnection(), Contact::class))
            ->withData(['firstName' => 'X', 'lastName' => 'Y', 'email' => 'x@y.test', 'account' => 1, 'tags' => [1, 2]])
            ->operation('create')
            ->get();
    }
}
