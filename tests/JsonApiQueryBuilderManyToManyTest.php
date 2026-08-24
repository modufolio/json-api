<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Tag;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * ManyToMany include resolution.
 *
 * ManyToMany used to fall through to the LEFT JOIN branch, which emitted a
 * single join to the target table with an ON condition referencing a join table
 * that was never joined — malformed SQL that also multiplied rows and so made
 * LIMIT slice joined rows instead of records. It is now resolved through the
 * join table by fetchToManyIncludes(), the same batched IN that OneToMany uses.
 */
class JsonApiQueryBuilderManyToManyTest extends TestCase
{
    private EntityManager $em;
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();
        // The entity manager is a shared static; without this the fixtures of
        // every earlier case are still in the database.
        TestDatabaseSetup::reset();

        $this->config = [
            Contact::class => [
                'resource_key'  => 'contact',
                'fields'        => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account', 'tags'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Tag::class => [
                'resource_key'  => 'tag',
                'fields'        => ['id', 'label', 'color'],
                'relationships' => ['contacts'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Account::class => [
                'resource_key'  => 'account',
                'fields'        => ['id', 'name'],
                'relationships' => ['contacts'],
                'operations'    => ['index' => true, 'show' => true],
            ],
        ];

        $account = new Account();
        $account->setName('Acme Corp');
        $this->em->persist($account);

        $urgent = (new Tag())->setLabel('urgent')->setColor('red');
        $vip = (new Tag())->setLabel('vip')->setColor('gold');
        $this->em->persist($urgent);
        $this->em->persist($vip);

        // John carries both tags, Jane one, Alice none — so a correct result
        // cannot be produced by accident from a uniform fixture.
        foreach ([
            ['John', [$urgent, $vip]],
            ['Jane', [$urgent]],
            ['Alice', []],
        ] as [$first, $tags]) {
            $contact = new Contact();
            $contact->setFirstName($first);
            $contact->setLastName('Doe');
            $contact->setEmail(strtolower($first) . '@acme.com');
            $contact->setAccount($account);

            foreach ($tags as $tag) {
                $contact->addTag($tag);
            }

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

    private function builder(string $resourceClass = Contact::class): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $resourceClass
        );
    }

    public function testOwningSideDoesNotMultiplyRows(): void
    {
        $result = $this->builder()->include(['tags'])->operation('index')->get();

        self::assertCount(3, $result['data'], 'One row per contact, regardless of tag count.');
    }

    public function testOwningSideLinkageIsPerRecord(): void
    {
        $result = $this->builder()->include(['tags'])->operation('index')->get();

        $labels = [];

        foreach ($result['data'] as $row) {
            $ids = array_map(
                static fn (array $identifier): string => $identifier['id'],
                $row['relationships']['tags']['data'] ?? []
            );
            $labels[$row['attributes']['first_name']] = count($ids);
        }

        self::assertSame(2, $labels['John']);
        self::assertSame(1, $labels['Jane']);
        self::assertSame(0, $labels['Alice']);
    }

    public function testOwningSideIncludesTagAttributes(): void
    {
        $result = $this->builder()->include(['tags'])->operation('index')->get();

        $byId = [];

        foreach ($result['included'] as $row) {
            self::assertSame('tag', $row['type']);
            $byId[$row['id']] = $row['attributes'];
        }

        self::assertCount(2, $byId, 'Included tags are deduplicated across contacts.');

        $labels = array_column($byId, 'label');
        sort($labels);

        self::assertSame(['urgent', 'vip'], $labels);
    }

    /**
     * The inverse side reads the same join table with its two columns swapped;
     * getting that backwards silently returns the wrong records rather than
     * failing, so it is asserted explicitly.
     */
    public function testInverseSideResolvesThroughTheSameJoinTable(): void
    {
        $result = $this->builder(Tag::class)->include(['contacts'])->operation('index')->get();

        $counts = [];

        foreach ($result['data'] as $row) {
            $counts[$row['attributes']['label']] = count($row['relationships']['contacts']['data'] ?? []);
        }

        self::assertSame(2, $counts['urgent'], 'urgent is on John and Jane.');
        self::assertSame(1, $counts['vip'], 'vip is on John only.');
    }

    public function testPaginationCountsRecordsNotJoinedRows(): void
    {
        $result = $this->builder()
            ->include(['tags'])
            ->page(1, 2)
            ->operation('index')
            ->withTotalCount()
            ->get();

        self::assertCount(2, $result['data'], 'LIMIT applies to contacts.');
        self::assertSame(3, $result['total'], 'The total counts contacts, not contact-tag pairs.');
    }

    public function testLinkageIsPresentWithoutExplicitInclude(): void
    {
        $result = $this->builder()->operation('index')->get();

        $john = null;

        foreach ($result['data'] as $row) {
            if ($row['attributes']['first_name'] === 'John') {
                $john = $row;
            }
        }

        self::assertNotNull($john);
        self::assertCount(2, $john['relationships']['tags']['data'] ?? []);
        self::assertSame([], $result['included'], 'Attributes are only fetched for requested includes.');
    }
}
