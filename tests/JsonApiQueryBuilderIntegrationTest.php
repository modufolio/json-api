<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderIntegrationTest extends TestCase
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
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('attributes', $result[0]);
        $this->assertEquals('Johnny', $result[0]['attributes']['first_name']);
        $this->assertEquals('johnny@test.com', $result[0]['attributes']['email']);
    }

    public function testDeleteOperation(): void
    {
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['firstName' => 'John']);
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

        $result = $queryBuilder
            ->group('firstName')
            ->having('COUNT(*) >= 1')
            ->operation('index')
            ->get();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThanOrEqual(1, count($result['data']));
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
}
