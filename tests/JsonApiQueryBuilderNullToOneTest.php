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
 * How an empty to-one is serialized.
 *
 * A null foreign key used to drop the relationship member altogether, which a
 * client cannot tell apart from a relationship the resource does not expose.
 * JSON:API spells an empty to-one as `{"data": null}`, and that is what the
 * builder emits now.
 */
class JsonApiQueryBuilderNullToOneTest extends TestCase
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

        // An account is mandatory on a contact; an organization is not.
        $placed = new Contact();
        $placed->setFirstName('Placed');
        $placed->setLastName('Doe');
        $placed->setEmail('placed@acme.com');
        $placed->setAccount($account);
        $placed->setOrganization($organization);
        $this->em->persist($placed);

        $orphan = new Contact();
        $orphan->setFirstName('Orphan');
        $orphan->setLastName('Doe');
        $orphan->setEmail('orphan@acme.com');
        $orphan->setAccount($account);
        $this->em->persist($orphan);

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        // Leave a clean database for the next class: the entity manager is
        // shared and the older suites assume they start empty.
        TestDatabaseSetup::reset();
    }

    /**
     * A client must be able to tell "no organization" from "organization not
     * exposed here".
     */
    public function testNullToOneIsEmittedAsDataNull(): void
    {
        $result = $this->builder()
            ->filter(['firstName' => 'Orphan'])
            ->operation('index')
            ->get();

        self::assertCount(1, $result['data']);
        $relationships = $result['data'][0]['relationships'];

        self::assertSame(['data' => null], $relationships['organization']);
        self::assertSame('account', $relationships['account']['data']['type']);
    }

    public function testPresentToOneStillCarriesItsIdentifier(): void
    {
        $result = $this->builder()
            ->filter(['firstName' => 'Placed'])
            ->operation('index')
            ->get();

        $organization = $result['data'][0]['relationships']['organization'];

        self::assertSame('organization', $organization['data']['type']);
        self::assertNotSame('', $organization['data']['id']);
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
}
