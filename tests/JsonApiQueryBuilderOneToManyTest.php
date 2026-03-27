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
 * Tests for OneToMany include resolution via fetchToManyIncludes().
 *
 * The fix avoids LEFT JOIN row multiplication by resolving OneToMany
 * relationships through separate IN-queries after the main query.
 */
class JsonApiQueryBuilderOneToManyTest extends TestCase
{
    private EntityManager $em;
    private array $config;
    private Account $account;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();

        $this->config = [
            Account::class => [
                'resource_key' => 'account',
                'fields'        => ['id', 'name'],
                'relationships' => ['contacts', 'organizations'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Contact::class => [
                'resource_key' => 'contact',
                'fields'        => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Organization::class => [
                'resource_key' => 'organization',
                'fields'        => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations'    => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Acme Corp');
        $this->em->persist($account);

        foreach ([
            ['John', 'Doe', 'john@acme.com'],
            ['Jane', 'Smith', 'jane@acme.com'],
            ['Alice', 'Brown', 'alice@acme.com'],
        ] as [$first, $last, $email]) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName($last);
            $contact->setEmail($email);
            $contact->setAccount($account);
            $this->em->persist($contact);
        }

        $org = new Organization();
        $org->setName('Acme HQ');
        $org->setEmail('hq@acme.com');
        $org->setAccount($account);
        $this->em->persist($org);

        $this->em->flush();
        $this->account = $account;
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    private function makeQb(string $class = Account::class): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $class
        );
    }

    // -------------------------------------------------------------------------
    // index operation
    // -------------------------------------------------------------------------

    public function testOneToManyIncludeOnIndexReturnsIncluded(): void
    {
        $result = $this->makeQb()
            ->include(['contacts'])
            ->operation('index')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertCount(3, $result['included']);

        $types = array_unique(array_column($result['included'], 'type'));
        $this->assertEquals(['contact'], $types);
    }

    public function testOneToManyIncludeDoesNotMultiplyRows(): void
    {
        // Account has 3 contacts — without the fix a LEFT JOIN would yield 3 rows.
        $result = $this->makeQb()
            ->include(['contacts'])
            ->operation('index')
            ->get();

        $this->assertCount(1, $result['data'], 'Account row must not be duplicated for each contact');
    }

    public function testOneToManyIncludePopulatesRelationshipOnParent(): void
    {
        $result = $this->makeQb()
            ->include(['contacts'])
            ->operation('index')
            ->get();

        $accountItem = $result['data'][0];
        $this->assertArrayHasKey('relationships', $accountItem);
        $this->assertArrayHasKey('contacts', $accountItem['relationships']);

        $relData = $accountItem['relationships']['contacts']['data'];
        $this->assertCount(3, $relData);

        foreach ($relData as $identifier) {
            $this->assertEquals('contact', $identifier['type']);
            $this->assertArrayHasKey('id', $identifier);
        }
    }

    public function testOneToManyMultipleIncludesOnIndex(): void
    {
        $result = $this->makeQb()
            ->include(['contacts', 'organizations'])
            ->operation('index')
            ->get();

        $this->assertArrayHasKey('included', $result);

        $types = array_unique(array_column($result['included'], 'type'));
        sort($types);
        $this->assertEquals(['contact', 'organization'], $types);

        $accountItem = $result['data'][0];
        $this->assertArrayHasKey('contacts', $accountItem['relationships']);
        $this->assertArrayHasKey('organizations', $accountItem['relationships']);
    }

    public function testOneToManyIncludedItemsAreDeduplicatedInIncluded(): void
    {
        // Add a second account that shares no contacts — verify the included map
        // does not create duplicate entries.
        $account2 = new Account();
        $account2->setName('Second Corp');
        $this->em->persist($account2);

        $contact = new Contact();
        $contact->setFirstName('Bob');
        $contact->setLastName('Lee');
        $contact->setEmail('bob@second.com');
        $contact->setAccount($account2);
        $this->em->persist($contact);

        $this->em->flush();

        $result = $this->makeQb()
            ->include(['contacts'])
            ->operation('index')
            ->get();

        $ids = array_column($result['included'], 'id');
        $this->assertEquals(count($ids), count(array_unique($ids)), 'Included items must not be duplicated');
    }

    public function testOneToManyIncludeEmptyRelationshipReturnsEmptyData(): void
    {
        // Create an account with no contacts.
        $emptyAccount = new Account();
        $emptyAccount->setName('Empty Corp');
        $this->em->persist($emptyAccount);
        $this->em->flush();

        // Query only the empty account.
        $emptyId = (string) $emptyAccount->getId();

        // Use a filter to narrow to only the account without contacts.
        // The relationships.contacts.data key should be an empty array.
        $result = $this->makeQb()
            ->include(['contacts'])
            ->filter(['name' => 'Empty Corp'])
            ->operation('index')
            ->get();

        $this->assertCount(1, $result['data']);
        $accountItem = $result['data'][0];

        // No contacts were found, so the relationship block is absent or empty.
        // The fix sets an empty array when there are no items for that parent.
        $relData = $accountItem['relationships']['contacts']['data'] ?? [];
        $this->assertEmpty($relData);
    }

    public function testOneToManyIncludeWithTotalCountIncludesIncludedKey(): void
    {
        $result = $this->makeQb()
            ->include(['contacts'])
            ->withTotalCount()
            ->operation('index')
            ->get();

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertEquals(1, $result['total']);
        $this->assertCount(3, $result['included']);
    }

    public function testNoIncludeProducesEmptyIncluded(): void
    {
        $result = $this->makeQb()
            ->operation('index')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertEmpty($result['included']);
    }

    // -------------------------------------------------------------------------
    // show operation
    // -------------------------------------------------------------------------

    public function testOneToManyIncludeOnShowReturnsIncluded(): void
    {
        $id = (string) $this->account->getId();

        $result = $this->makeQb()
            ->include(['contacts'])
            ->operation('show')
            ->withId($id)
            ->get();

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertCount(3, $result['included']);

        $item = $result[0];
        $this->assertArrayHasKey('relationships', $item);
        $this->assertArrayHasKey('contacts', $item['relationships']);
        $this->assertCount(3, $item['relationships']['contacts']['data']);
    }

    public function testOneToManyShowMultipleIncludes(): void
    {
        $id = (string) $this->account->getId();

        $result = $this->makeQb()
            ->include(['contacts', 'organizations'])
            ->operation('show')
            ->withId($id)
            ->get();

        $this->assertArrayHasKey('included', $result);

        $types = array_unique(array_column($result['included'], 'type'));
        sort($types);
        $this->assertEquals(['contact', 'organization'], $types);
    }

    public function testOneToManyShowWithoutIncludeHasConsistentShape(): void
    {
        $id = (string) $this->account->getId();

        $result = $this->makeQb()
            ->operation('show')
            ->withId($id)
            ->get();

        // Always returns [0 => $item, 'included' => []] regardless of whether includes were requested.
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertEmpty($result['included']);
        $this->assertEquals('Acme Corp', $result[0]['attributes']['name']);
    }
}
