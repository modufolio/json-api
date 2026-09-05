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
 * `scopeBy()` makes a scope required rather than remembered.
 *
 * Row scoping was uniformly enforced on every operation — once `scope()` had
 * been called. Nothing noticed when it had not, and the query then ran
 * successfully across every tenant. That is the shape of failure this closes:
 * a resource declared scoped refuses to execute unscoped, so the omission is
 * an exception on the first request rather than a leak in production.
 *
 * The waiver is the other half. An unscoped query is sometimes right — an
 * admin report, a console command — so `withoutScope()` exists to say so at
 * the call site, where a reviewer can tell "global on purpose" apart from
 * "nobody remembered".
 */
class JsonApiQueryBuilderScopeRequiredTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;
    private Account $tenantA;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Contact::class => [
                'resource_key' => 'contact',
                'fields' => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account'],
                'operations' => ['index' => true, 'show' => true, 'create' => true, 'update' => true, 'delete' => true],
                'scope_by' => ['account'],
            ],
        ];

        $this->tenantA = new Account();
        $this->tenantA->setName('Tenant A');
        $this->em->persist($this->tenantA);

        $tenantB = new Account();
        $tenantB->setName('Tenant B');
        $this->em->persist($tenantB);

        $contactA = new Contact();
        $contactA->setFirstName('Alice');
        $contactA->setEmail('alice@a.test');
        $contactA->setAccount($this->tenantA);
        $this->em->persist($contactA);

        $contactB = new Contact();
        $contactB->setFirstName('Bob');
        $contactB->setEmail('bob@b.test');
        $contactB->setAccount($tenantB);
        $this->em->persist($contactB);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
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

    private function accountId(): int
    {
        $id = $this->tenantA->getId();
        $this->assertNotNull($id);

        return $id;
    }

    public function testIndexRefusesToRunWithoutAScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declared scoped by "account"/');

        $this->builder()->operation('index')->get();
    }

    /**
     * The message has to say what to do, because it fires at the moment
     * someone is writing the call that forgot.
     */
    public function testTheRefusalNamesBothWaysOut(): void
    {
        try {
            $this->builder()->operation('index')->get();
            $this->fail('Expected the unscoped query to be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('scope([...])', $e->getMessage());
            $this->assertStringContainsString('withoutScope()', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOperations(): iterable
    {
        yield 'index' => ['index'];
        yield 'show' => ['show'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
        yield 'create' => ['create'];
    }

    /**
     * Every operation, not just the read: a scope forgotten on delete is worse
     * than one forgotten on index.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideOperations')]
    public function testEveryOperationRefusesToRunUnscoped(string $operation): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->operation($operation)->withId('1')->get();
    }

    /**
     * An unscoped COUNT tells you how many rows the other tenants have, so the
     * aggregates are guarded on the same footing as the rows.
     */
    public function testAggregatesRefuseToRunUnscoped(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->count();
    }

    public function testAScopedQueryRuns(): void
    {
        $result = $this->builder()
            ->scope(['account' => $this->accountId()])
            ->operation('index')
            ->get();

        $this->assertCount(1, $result['data']);
        $this->assertSame('Alice', $result['data'][0]['attributes']['first_name']);
    }

    public function testScopedAggregatesRun(): void
    {
        $count = $this->builder()
            ->scope(['account' => $this->accountId()])
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * The deliberate escape still works, and still returns everything — the
     * guard is about intent, not about forbidding global queries.
     */
    public function testWithoutScopeRunsAcrossEveryTenant(): void
    {
        $result = $this->builder()
            ->withoutScope()
            ->operation('index')
            ->get();

        $this->assertCount(2, $result['data']);
    }

    /**
     * A resource that declares no scope is untouched — this must not become a
     * tax on every entity in the config.
     */
    public function testAnUndeclaredResourceIsUnaffected(): void
    {
        unset($this->config[Contact::class]['scope_by']);

        $result = $this->builder()->operation('index')->get();

        $this->assertCount(2, $result['data']);
    }

    /**
     * A scope naming a different column than the declaration does not satisfy
     * it — otherwise any scope at all would tick the box.
     */
    public function testAScopeOnAnotherFieldDoesNotSatisfyTheDeclaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no scope was set for "account"/');

        $this->builder()
            ->scope(['email' => 'alice@a.test'])
            ->operation('index')
            ->get();
    }
}
