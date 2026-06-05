<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * buildFilterRegistry() used to always add an UNSCOPED catch-all
 * JsonApiFilterHandler, which ANDed `field = value` onto every field — including
 * fields owned by a SearchFilter. The result was `firstName = 'J' AND firstName
 * LIKE '%J%'`, silently collapsing partial search to exact match.
 *
 * The catch-all must now be scoped off the fields a declared field-specific
 * filter already owns.
 */
class BuildFilterRegistrySearchTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $account = new Account();
        $account->setName('Acme');
        $this->em->persist($account);

        foreach ([['John', 'Smith'], ['Jane', 'Johnson'], ['Bob', 'Brown']] as [$first, $last]) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName($last);
            $contact->setEmail(strtolower($first) . '@example.com');
            $contact->setAccount($account);
            $this->em->persist($contact);
        }

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function configurator(): JsonApiConfigurator
    {
        $api = new JsonApiConfigurator();
        $api->resource(Contact::class)
            ->key('contact')
            ->fields(['id', 'firstName', 'lastName', 'email'])
            ->relationships(['account'])
            ->operations(['index' => true, 'show' => true]);

        return $api;
    }

    public function testPartialSearchSurvivesTheDefaultCatchAll(): void
    {
        $api = $this->configurator();
        $api->filters(Contact::class, [
            new SearchFilter(['firstName' => SearchStrategy::PARTIAL]),
        ]);

        $registry = $api->buildFilterRegistry();
        $config = $api->buildConfig();

        $qb = new JsonApiQueryBuilder(
            $config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // Partial: 'J' must match both John and Jane. With the old unscoped
        // catch-all this returned 0 (no firstName is literally 'J').
        $result = $qb->filter(['firstName' => 'J'])->operation('index')->get();

        $this->assertCount(2, $result['data']);
        $names = array_map(fn ($c) => $c['attributes']['first_name'], $result['data']);
        $this->assertContains('John', $names);
        $this->assertContains('Jane', $names);
    }

    public function testCatchAllStillHandlesNonSearchFields(): void
    {
        $api = $this->configurator();
        $api->filters(Contact::class, [
            new SearchFilter(['firstName' => SearchStrategy::PARTIAL]),
        ]);

        $registry = $api->buildFilterRegistry();
        $config = $api->buildConfig();

        $qb = new JsonApiQueryBuilder(
            $config,
            $this->em,
            $this->em->getConnection(),
            Contact::class,
            $registry
        );

        // lastName is not owned by the SearchFilter, so the catch-all still
        // applies exact match and its operators there.
        $result = $qb->filter(['lastName' => 'Smith'])->operation('index')->get();

        $this->assertCount(1, $result['data']);
        $this->assertSame('John', $result['data'][0]['attributes']['first_name']);
    }

    public function testRegistryScopesCatchAllOffSearchFields(): void
    {
        $api = $this->configurator();
        $api->filters(Contact::class, [
            new SearchFilter(['firstName' => SearchStrategy::PARTIAL]),
        ]);

        $filters = $api->buildFilterRegistry()->getFilters(Contact::class);

        // catch-all first, then the declared SearchFilter.
        $this->assertCount(2, $filters);
        $this->assertInstanceOf(JsonApiFilterHandler::class, $filters[0]);
        $this->assertInstanceOf(SearchFilter::class, $filters[1]);

        $catchAll = $filters[0];
        $this->assertFalse($catchAll->supports('firstName'), 'catch-all must not claim the SearchFilter field');
        $this->assertTrue($catchAll->supports('lastName'));
        $this->assertTrue($catchAll->supports('email'));
    }

    public function testNoFieldSpecificFiltersKeepsUnscopedCatchAll(): void
    {
        // No filters() declared at all → behaviour is unchanged: a single
        // catch-all that handles every field.
        $filters = $this->configurator()->buildFilterRegistry()->getFilters(Contact::class);

        $this->assertCount(1, $filters);
        $this->assertInstanceOf(JsonApiFilterHandler::class, $filters[0]);
        $this->assertTrue($filters[0]->supports('firstName'));
        $this->assertTrue($filters[0]->supports('anythingAtAll'));
    }

    public function testEveryFieldCoveredRegistersNoUnscopedCatchAll(): void
    {
        $api = $this->configurator();
        // SearchFilter owns every configured field → no catch-all should remain,
        // because an empty allow-list would mean "all fields" and reintroduce the
        // exact-match conflict.
        $api->filters(Contact::class, [
            new SearchFilter([
                'id'        => SearchStrategy::EXACT,
                'firstName' => SearchStrategy::PARTIAL,
                'lastName'  => SearchStrategy::PARTIAL,
                'email'     => SearchStrategy::PARTIAL,
            ]),
        ]);

        $filters = $api->buildFilterRegistry()->getFilters(Contact::class);

        $this->assertCount(1, $filters);
        $this->assertInstanceOf(SearchFilter::class, $filters[0]);
    }
}
