<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * Columns selected from a joined to-one, and the pagination escape hatch.
 *
 * buildJoins() used to select `reset($allowedFields)` — the first configured
 * field and nothing else — so a caller needing two columns off a joined record
 * (a person's first *and* last name) could not ask for the second. It now
 * selects every allowed field, narrowed by a sparse fieldset when one is given.
 */
class JsonApiQueryBuilderJoinFieldsTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();
        TestDatabaseSetup::reset();

        $this->config = [
            Contact::class => [
                'resource_key'  => 'contact',
                'fields'        => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account', 'organization'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Account::class => [
                'resource_key'  => 'account',
                'fields'        => ['id', 'name'],
                'relationships' => ['contacts'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Organization::class => [
                'resource_key'  => 'organization',
                'fields'        => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations'    => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Acme Corp');
        $this->em->persist($account);

        $organization = new Organization();
        $organization->setName('Acme Labs');
        $organization->setEmail('labs@acme.com');
        $organization->setAccount($account);
        $this->em->persist($organization);

        foreach (['John', 'Jane', 'Alice'] as $first) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName('Doe');
            $contact->setEmail(strtolower($first) . '@acme.com');
            $contact->setAccount($account);
            $contact->setOrganization($organization);
            $this->em->persist($contact);
        }

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        // Leave a clean database for the next class: the entity manager is
        // shared and the older suites assume they start empty.
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

    public function testEveryAllowedFieldOfAJoinedToOneIsSelected(): void
    {
        $result = $this->builder()->include(['organization'])->operation('index')->get();

        $attributes = $result['data'][0]['attributes'];

        // Organization exposes id, name and email — not just the first of them.
        self::assertSame('Acme Labs', $attributes['organization_name']);
        self::assertSame('labs@acme.com', $attributes['organization_email']);
    }

    public function testSparseFieldsetNarrowsAJoinedToOne(): void
    {
        $result = $this->builder()
            ->include(['organization'])
            ->fields(['organization' => ['name']])
            ->operation('index')
            ->get();

        $attributes = $result['data'][0]['attributes'];

        self::assertSame('Acme Labs', $attributes['organization_name']);
        self::assertArrayNotHasKey('organization_email', $attributes);
    }

    /**
     * A fieldset naming only fields the resource does not expose must not
     * widen access; the configured allow-list stays in charge.
     */
    public function testSparseFieldsetCannotReachUnexposedFields(): void
    {
        $result = $this->builder()
            ->include(['organization'])
            ->fields(['organization' => ['secretColumn']])
            ->operation('index')
            ->get();

        $attributes = $result['data'][0]['attributes'];

        self::assertArrayNotHasKey('organization_secretColumn', $attributes);
        self::assertSame('Acme Labs', $attributes['organization_name']);
    }

    public function testJoinedToOneStillDoesNotMultiplyRows(): void
    {
        $result = $this->builder()->include(['organization', 'account'])->operation('index')->get();

        self::assertCount(3, $result['data']);
    }

    public function testWithoutPaginationReturnsEveryRow(): void
    {
        $result = $this->builder()->page(1, 2)->withoutPagination()->operation('index')->get();

        self::assertCount(3, $result['data'], 'withoutPagination() overrides an earlier page().');
    }

    public function testDefaultPaginationStillApplies(): void
    {
        $result = $this->builder()->page(1, 2)->operation('index')->get();

        self::assertCount(2, $result['data']);
    }

    public function testWithoutPaginationDoesNotEmitALimit(): void
    {
        $sql = $this->builder()->withoutPagination()->operation('index')->toSql();

        self::assertStringNotContainsStringIgnoringCase('LIMIT', $sql);
    }

    /**
     * There is no JSON:API parameter meaning "no limit", so the URI must omit
     * pagination entirely rather than emit an empty `page[size]=`, which would
     * parse back as a different query.
     */
    public function testWithoutPaginationOmitsPageParamsFromTheUri(): void
    {
        $uri = $this->builder()->withoutPagination()->operation('index')->buildUri();

        self::assertStringNotContainsString('page[size]', $uri);
        self::assertStringNotContainsString('page[number]', $uri);
    }

    public function testExplicitPageStillAppearsInTheUri(): void
    {
        $uri = $this->builder()->page(2, 5)->operation('index')->buildUri();

        self::assertStringContainsString('page[number]=2', $uri);
        self::assertStringContainsString('page[size]=5', $uri);
    }
}
