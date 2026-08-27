<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Platform;

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\Tests\Fixtures\Entity\Product;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour that must not depend on which engine the application runs.
 *
 * Left to the engines, the same request returns different rows: PostgreSQL
 * matches LIKE case-sensitively while MySQL and SQLite do not, and they
 * disagree about where NULLs belong in a sort. Run this file under DB_DRIVER
 * for each engine — the assertions are identical by design.
 */
class CrossEngineContractTest extends TestCase
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

        // Mixed case, and a null in the sorted column.
        foreach ([['Alice', 2], ['bob', null], ['Carol', 1]] as [$name, $price]) {
            $product = (new Product())->setName($name)->setPrice($price)->setActive(true);
            $this->em->persist($product);
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function builder(?FilterRegistry $registry = null): JsonApiQueryBuilder
    {
        return new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            Product::class,
            $registry,
        );
    }

    /**
     * @return list<string>
     */
    private function names(JsonApiQueryBuilder $builder): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['attributes']['name'],
            $builder->operation('index')->get()['data'],
        ));
    }

    /**
     * PostgreSQL puts NULLs last ascending and first descending; MySQL and
     * SQLite do the opposite. Pinned to last in both directions, so a client
     * paging a sorted collection sees the same first page everywhere.
     */
    public function testNullsSortLastAscending(): void
    {
        $names = $this->names($this->builder()->sort(['price']));

        $this->assertSame(['Carol', 'Alice', 'bob'], $names);
    }

    public function testNullsSortLastDescendingToo(): void
    {
        $names = $this->names($this->builder()->sort(['-price']));

        $this->assertSame(['Alice', 'Carol', 'bob'], $names);
    }

    /**
     * Whether LIKE folds case is a property of the column's collation, which
     * nobody chose deliberately. Search is case-insensitive everywhere.
     */
    public function testPartialSearchIgnoresCase(): void
    {
        $registry = new FilterRegistry();
        $registry->register(Product::class, new SearchFilter(['name' => SearchStrategy::PARTIAL]));

        $names = $this->names($this->builder($registry)->filter(['name' => 'A']));

        // 'Alice' by its first letter, 'Carol' by the 'a' in the middle.
        sort($names);
        $this->assertSame(['Alice', 'Carol'], $names);
    }

    public function testExactSearchIgnoresCaseToo(): void
    {
        $registry = new FilterRegistry();
        $registry->register(Product::class, new SearchFilter(['name' => SearchStrategy::EXACT]));

        $names = $this->names($this->builder($registry)->filter(['name' => 'BOB']));

        $this->assertSame(['bob'], $names);
    }

    public function testStartSearchIgnoresCase(): void
    {
        $registry = new FilterRegistry();
        $registry->register(Product::class, new SearchFilter(['name' => SearchStrategy::START]));

        $names = $this->names($this->builder($registry)->filter(['name' => 'c']));

        $this->assertSame(['Carol'], $names);
    }
}
