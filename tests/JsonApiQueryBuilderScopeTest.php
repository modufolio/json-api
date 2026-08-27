<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * Row-level scoping: one declaration ("this caller may only touch tenant A's
 * rows") enforced uniformly on index, show, update, delete and create.
 *
 * The point of the uniformity is the advisory-shaped failure it prevents:
 * scopes applied on read but forgotten on update/delete are how records leak
 * across tenants.
 */
class JsonApiQueryBuilderScopeTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;
    private Account $tenantA;
    private Account $tenantB;
    private Contact $contactA;
    private Contact $contactB;

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

        $this->tenantA = new Account();
        $this->tenantA->setName('Tenant A');
        $this->em->persist($this->tenantA);

        $this->tenantB = new Account();
        $this->tenantB->setName('Tenant B');
        $this->em->persist($this->tenantB);

        $this->contactA = new Contact();
        $this->contactA->setFirstName('Alice');
        $this->contactA->setEmail('alice@a.test');
        $this->contactA->setAccount($this->tenantA);
        $this->em->persist($this->contactA);

        $this->contactB = new Contact();
        $this->contactB->setFirstName('Bob');
        $this->contactB->setEmail('bob@b.test');
        $this->contactB->setAccount($this->tenantB);
        $this->em->persist($this->contactB);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function idOf(Account|Contact $entity): int
    {
        $id = $entity->getId();
        $this->assertNotNull($id);

        return $id;
    }

    private function builder(): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Contact::class
        );
    }

    public function testIndexOnlyReturnsRowsInsideTheScope(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('index')
            ->withTotalCount()
            ->get();

        $this->assertCount(1, $result['data']);
        $this->assertSame('Alice', $result['data'][0]['attributes']['first_name']);
        $this->assertSame(1, $result['total'], 'The pager total must be scoped too.');
    }

    public function testShowOfAnOutOfScopeRowReportsItAsMissing(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('show')
            ->withId((string) $this->idOf($this->contactB))
            ->get();

        $this->assertNull($result['data']);
    }

    public function testShowOfAnInScopeRowStillWorks(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('show')
            ->withId((string) $this->idOf($this->contactA))
            ->get();

        $this->assertSame('Alice', $result['data']['attributes']['first_name']);
    }

    public function testUpdateRefusesToTouchAnOutOfScopeRow(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('update')
            ->withId((string) $this->idOf($this->contactB))
            ->withData(['firstName' => 'Hijacked'])
            ->get();

        // Indistinguishable from a missing record ...
        $this->assertNull($result['data']);

        // ... and the other tenant's row is untouched.
        $this->em->clear();
        $fresh = $this->em->find(Contact::class, $this->idOf($this->contactB));
        $this->assertInstanceOf(Contact::class, $fresh);
        $this->assertSame('Bob', $fresh->getFirstName());
    }

    public function testUpdateInsideTheScopeStillWorks(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('update')
            ->withId((string) $this->idOf($this->contactA))
            ->withData(['firstName' => 'Alicia'])
            ->get();

        $this->assertSame('Alicia', $result['data']['attributes']['first_name']);
    }

    public function testDeleteRefusesToTouchAnOutOfScopeRow(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('delete')
            ->withId((string) $this->idOf($this->contactB))
            ->get();

        $this->assertNull($result['data']);

        $this->em->clear();
        $this->assertNotNull($this->em->find(Contact::class, $this->idOf($this->contactB)));
    }

    public function testDeleteInsideTheScopeStillWorks(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('delete')
            ->withId((string) $this->idOf($this->contactA))
            ->get();

        $this->assertSame('deleted', $result['status']);

        $this->em->clear();
        $this->assertNull($this->em->find(Contact::class, $this->idOf($this->contactA)));
    }

    public function testDeleteOfAMissingRowReportsNullData(): void
    {
        $result = $this->builder()
            ->operation('delete')
            ->withId('999999')
            ->get();

        $this->assertNull($result['data']);
    }

    public function testCreateForcesTheScopedValueOverClientInput(): void
    {
        // The client cannot pick the tenant: whatever it sends, the row is
        // created inside the scope.
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('create')
            ->withData(['firstName' => 'Carol', 'email' => 'carol@a.test'])
            ->get();

        $this->assertSame('Carol', $result['data']['attributes']['first_name']);

        $this->em->clear();
        $created = $this->em->find(Contact::class, (int) $result['data']['id']);
        $this->assertInstanceOf(Contact::class, $created);
        $account = $created->getAccount();
        $this->assertInstanceOf(Account::class, $account);
        $this->assertSame($this->idOf($this->tenantA), $account->getId());
    }

    public function testCreateWithListScopeRejectsAValueOutsideIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the enforced scope');

        // A list scope cannot pick the value for the client — and the client
        // sent nothing, so there is no in-scope value to accept.
        $this->builder()
            ->scope(['account' => [$this->idOf($this->tenantA), $this->idOf($this->tenantB)]])
            ->operation('create')
            ->withData(['firstName' => 'Dave'])
            ->get();
    }

    public function testListScopeMatchesAnyOfItsValues(): void
    {
        $result = $this->builder()
            ->scope(['account' => [$this->idOf($this->tenantA), $this->idOf($this->tenantB)]])
            ->operation('index')
            ->get();

        $this->assertCount(2, $result['data']);
    }

    public function testScopeSurvivesBuilderReuse(): void
    {
        $builder = $this->builder()->scope(['account' => $this->idOf($this->tenantA)]);

        // First operation runs and resets the builder ...
        $builder->operation('index')->get();

        // ... but the scope is security state and must not reset with it.
        $result = $builder
            ->operation('show')
            ->withId((string) $this->idOf($this->contactB))
            ->get();

        $this->assertNull($result['data']);
    }

    public function testScopeByPlainFieldWorks(): void
    {
        $result = $this->builder()
            ->scope(['email' => 'alice@a.test'])
            ->operation('index')
            ->get();

        $this->assertCount(1, $result['data']);
        $this->assertSame('Alice', $result['data'][0]['attributes']['first_name']);
    }

    public function testNullScopeMatchesOnlyNullColumns(): void
    {
        $result = $this->builder()
            ->scope(['phone' => null])
            ->operation('index')
            ->get();

        // Both fixtures have a NULL phone; a row with one set would be excluded.
        $this->assertCount(2, $result['data']);
    }

    public function testUnknownScopeKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown scope key 'tenant'");

        $this->builder()->scope(['tenant' => 1]);
    }

    public function testToManyScopeKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-many relationship');

        $this->builder()->scope(['tags' => 1]);
    }

    public function testEmptyListScopeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can never match');

        $this->builder()->scope(['account' => []]);
    }

    public function testScopedDebugUpdateCarriesTheScopeInItsSql(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->idOf($this->tenantA)])
            ->operation('update')
            ->withId((string) $this->idOf($this->contactA))
            ->withData(['firstName' => 'X'])
            ->debug()
            ->get();

        $this->assertStringContainsString('account_id', $result['query']);
        $this->assertStringContainsString('jsonapi_scope_0', $result['query']);
    }
}
