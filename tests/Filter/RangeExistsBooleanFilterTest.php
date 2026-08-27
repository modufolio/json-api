<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Modufolio\JsonApi\Filter\BooleanFilter;
use Modufolio\JsonApi\Filter\ExistsFilter;
use Modufolio\JsonApi\Filter\FilterInterface;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\RangeFilter;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Product;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * The three filters run against the database rather than against generated
 * SQL: a boolean bound as a string and an `= NULL` comparison both produce
 * valid SQL and the wrong rows, which only a real query reveals.
 */
class RangeExistsBooleanFilterTest extends TestCase
{
    private EntityManager $em;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->em = TestDatabaseSetup::createEntityManager();
        TestDatabaseSetup::reset();

        $this->config = [
            Product::class => [
                'resource_key'  => 'product',
                'fields'        => ['id', 'name', 'price', 'active', 'discontinuedAt'],
                'relationships' => [],
                'operations'    => ['index' => true, 'show' => true],
            ],
        ];

        foreach (
            [
                ['Anvil', 10, true, null],
                ['Rocket', 50, true, null],
                ['Birdseed', 100, false, '2026-01-01'],
                ['Dynamite', 250, false, null],
            ] as [$name, $price, $active, $discontinued]
        ) {
            $product = (new Product())
                ->setName($name)
                ->setPrice($price)
                ->setActive($active)
                ->setDiscontinuedAt($discontinued === null ? null : new \DateTimeImmutable($discontinued));
            $this->em->persist($product);
        }

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    private function names(FilterRegistry $registry, array $filters): array
    {
        $builder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Product::class,
            $registry,
        );

        $result = $builder->operation('index')->filter($filters)->sort(['price'])->get();

        return array_values(
            array_map(static fn (array $row): string => $row['attributes']['name'], $result['data'])
        );
    }

    private function registry(FilterInterface $filter): FilterRegistry
    {
        $registry = new FilterRegistry();
        $registry->register(Product::class, $filter);

        return $registry;
    }

    // ── RangeFilter ─────────────────────────────────────────────────────────

    public function testRangeBoundsAtBothEndsInclusively(): void
    {
        $names = $this->names(
            $this->registry(new RangeFilter(['price'])),
            ['price' => ['gte' => 50, 'lte' => 100]],
        );

        $this->assertSame(['Rocket', 'Birdseed'], $names);
    }

    public function testRangeStrictBoundsExcludeTheEndpoints(): void
    {
        $names = $this->names(
            $this->registry(new RangeFilter(['price'])),
            ['price' => ['gt' => 10, 'lt' => 250]],
        );

        $this->assertSame(['Rocket', 'Birdseed'], $names);
    }

    public function testBetweenIsInclusiveOnBothEnds(): void
    {
        $names = $this->names(
            $this->registry(new RangeFilter(['price'])),
            ['price' => ['between' => '10..50']],
        );

        $this->assertSame(['Anvil', 'Rocket'], $names);
    }

    public function testBetweenWithoutTwoBoundsIsRejected(): void
    {
        $this->expectException(QueryParamMalformed::class);
        $this->expectExceptionMessage("takes two bounds");

        $this->names(
            $this->registry(new RangeFilter(['price'])),
            ['price' => ['between' => '10']],
        );
    }

    public function testAnUnlistedFieldIsLeftAlone(): void
    {
        // The filter claims `price` only; `name` falls through untouched, so a
        // registry holding just this filter returns everything.
        $names = $this->names(
            $this->registry(new RangeFilter(['price'])),
            ['name' => ['gte' => 'Z']],
        );

        $this->assertCount(4, $names);
    }

    // ── ExistsFilter ────────────────────────────────────────────────────────

    public function testExistsFalseSelectsTheNullRows(): void
    {
        $names = $this->names(
            $this->registry(new ExistsFilter(['discontinuedAt'])),
            ['discontinuedAt' => ['exists' => 'false']],
        );

        $this->assertSame(['Anvil', 'Rocket', 'Dynamite'], $names);
    }

    public function testExistsTrueSelectsTheRowsCarryingAValue(): void
    {
        $names = $this->names(
            $this->registry(new ExistsFilter(['discontinuedAt'])),
            ['discontinuedAt' => ['exists' => 'true']],
        );

        $this->assertSame(['Birdseed'], $names);
    }

    /**
     * A typo must not quietly return the complementary set of rows.
     */
    public function testAnUnrecognisedExistsValueIsRejected(): void
    {
        $this->expectException(QueryParamMalformed::class);

        $this->names(
            $this->registry(new ExistsFilter(['discontinuedAt'])),
            ['discontinuedAt' => ['exists' => 'maybe']],
        );
    }

    // ── BooleanFilter ───────────────────────────────────────────────────────

    public function testBooleanTrueMatchesTheStoredRepresentation(): void
    {
        $names = $this->names(
            $this->registry(new BooleanFilter(['active'])),
            ['active' => 'true'],
        );

        $this->assertSame(['Anvil', 'Rocket'], $names);
    }

    public function testBooleanFalseMatchesTheStoredRepresentation(): void
    {
        $names = $this->names(
            $this->registry(new BooleanFilter(['active'])),
            ['active' => 'false'],
        );

        $this->assertSame(['Birdseed', 'Dynamite'], $names);
    }

    public function testBooleanAcceptsTheUsualSpellings(): void
    {
        foreach (['1', 'yes', 'on', 'TRUE'] as $spelling) {
            $names = $this->names(
                $this->registry(new BooleanFilter(['active'])),
                ['active' => $spelling],
            );

            $this->assertSame(['Anvil', 'Rocket'], $names, "Spelling: $spelling");
        }
    }

    public function testAnUnrecognisedBooleanIsRejected(): void
    {
        $this->expectException(QueryParamMalformed::class);

        $this->names(
            $this->registry(new BooleanFilter(['active'])),
            ['active' => 'perhaps'],
        );
    }

    /**
     * The parser drops operators it does not recognise, so a filter whose
     * operator never reaches it does nothing behind a real request while
     * working perfectly in a hand-driven test.
     */
    public function testTheNewOperatorsSurviveRequestParsing(): void
    {
        $parser = new \Modufolio\JsonApi\JsonApiUrlParser($this->config);
        $request = new \Nyholm\Psr7\ServerRequest(
            'GET',
            'http://example.com/products?filter[price][between]=10..50&filter[discontinuedAt][exists]=true',
        );

        $params = $parser->parse($request, Product::class);

        $this->assertSame(['between' => '10..50'], $params->filter['price']);
        $this->assertSame(['exists' => 'true'], $params->filter['discontinuedAt']);
    }

    public function testTheFiltersCombine(): void
    {
        $registry = new FilterRegistry();
        $registry->register(Product::class, new RangeFilter(['price']));
        $registry->register(Product::class, new BooleanFilter(['active']));
        $registry->register(Product::class, new ExistsFilter(['discontinuedAt']));

        $names = $this->names($registry, [
            'price' => ['lte' => 100],
            'active' => 'false',
            'discontinuedAt' => ['exists' => 'true'],
        ]);

        $this->assertSame(['Birdseed'], $names);
    }
}
