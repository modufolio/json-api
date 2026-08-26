<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Account;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Contact;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Organization;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Tag;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * Nested includes of the documented `comments.author` shape.
 *
 * These used to be a silent no-op: buildJoins() stopped at the first to-many
 * segment and fetchToManyIncludes() collapsed the path with
 * `explode('.', $path)[0]`, so the second segment was dropped without warning
 * even though the docs advertised it. A to-one second segment is now joined
 * onto the included rows, and anything deeper or non-to-one is rejected rather
 * than quietly ignored.
 */
class JsonApiQueryBuilderNestedIncludeTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();
        TestDatabaseSetup::reset();

        $this->config = [
            Account::class => [
                'resource_key'  => 'account',
                'fields'        => ['id', 'name'],
                'relationships' => ['contacts'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Contact::class => [
                'resource_key'  => 'contact',
                'fields'        => ['id', 'firstName', 'lastName', 'email'],
                'relationships' => ['account', 'organization', 'tags'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Organization::class => [
                'resource_key'  => 'organization',
                'fields'        => ['id', 'name', 'email'],
                'relationships' => ['account'],
                'operations'    => ['index' => true, 'show' => true],
            ],
            Tag::class => [
                'resource_key'  => 'tag',
                'fields'        => ['id', 'label', 'color'],
                'relationships' => ['contacts'],
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

        foreach (['John', 'Jane'] as $first) {
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
        TestDatabaseSetup::reset();
    }

    /**
     * @param class-string $resourceClass
     */
    private function builder(string $resourceClass = Account::class): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $resourceClass
        );
    }

    /**
     * The whole point: an included to-many row can carry a related record's
     * columns, not merely its foreign key. Reading a display name off a joined
     * record previously needed a second query by the caller.
     */
    public function testToOneNestedUnderToManyIsResolved(): void
    {
        $result = $this->builder()
            ->include(['contacts.organization'])
            ->operation('index')
            ->get();

        self::assertNotEmpty($result['included']);

        foreach ($result['included'] as $row) {
            self::assertSame('contact', $row['type']);
            self::assertSame('Acme Labs', $row['attributes']['organization_name']);
            self::assertSame('labs@acme.com', $row['attributes']['organization_email']);
        }
    }

    public function testNestedIncludeDoesNotMultiplyParentRows(): void
    {
        $result = $this->builder()
            ->include(['contacts.organization'])
            ->operation('index')
            ->get();

        self::assertCount(1, $result['data'], 'One account.');
        self::assertCount(2, $result['included'], 'Two contacts, each once.');
    }

    public function testNestedIncludeStillCarriesLinkage(): void
    {
        $result = $this->builder()
            ->include(['contacts.organization'])
            ->operation('index')
            ->get();

        self::assertCount(2, $result['data'][0]['relationships']['contacts']['data']);
    }

    public function testUnknownNestedSegmentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nope/');

        $this->builder()->include(['contacts.nope'])->operation('index')->get();
    }

    /**
     * A to-many under a to-many would need its own batched query per parent
     * set; failing is better than returning nothing and looking successful.
     */
    public function testToManyNestedUnderToManyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/to-one/');

        $this->builder()->include(['contacts.tags'])->operation('index')->get();
    }

    public function testPathDeeperThanOneNestingLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too deeply/');

        $this->builder()->include(['contacts.organization.account'])->operation('index')->get();
    }

    /**
     * Sparse fieldsets reach the nested resource too, so a caller can pull one
     * column off it rather than the whole allow-list.
     */
    public function testSparseFieldsetNarrowsTheNestedResource(): void
    {
        $result = $this->builder()
            ->include(['contacts.organization'])
            ->fields(['organization' => ['name']])
            ->operation('index')
            ->get();

        $attributes = $result['included'][0]['attributes'];

        self::assertSame('Acme Labs', $attributes['organization_name']);
        self::assertArrayNotHasKey('organization_email', $attributes);
    }
}
