<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Filter;

use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class JsonApiFilterHandlerTest extends TestCase
{
    private JsonApiFilterHandler $handler;
    private Connection $conn;
    private DBALQueryBuilder $qb;

    protected function setUp(): void
    {
        $this->handler = new JsonApiFilterHandler();
        $this->conn = $this->createMock(Connection::class);
        $this->qb = $this->createMock(DBALQueryBuilder::class);
    }

    public function testGetSupportedOperators(): void
    {
        $operators = $this->handler->getSupportedOperators();

        $this->assertIsArray($operators);
        $this->assertContains('eq', $operators);
        $this->assertContains('neq', $operators);
        $this->assertContains('gt', $operators);
        $this->assertContains('lt', $operators);
        $this->assertContains('like', $operators);
        $this->assertContains('in', $operators);
        $this->assertContains('null', $operators);
    }

    public function testSupportsOperatorReturnsTrueForValidOperator(): void
    {
        $this->assertTrue($this->handler->supportsOperator('gt'));
        $this->assertTrue($this->handler->supportsOperator('like'));
        $this->assertTrue($this->handler->supportsOperator('in'));
    }

    public function testSupportsOperatorReturnsFalseForInvalidOperator(): void
    {
        $this->assertFalse($this->handler->supportsOperator('invalid'));
        $this->assertFalse($this->handler->supportsOperator('unknown'));
    }

    public function testApplySimpleFilter(): void
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->from('articles', 't0');

        $filters = ['title' => 'Test'];
        $fieldMappings = [
            'title' => ['columnName' => 'title']
        ];

        $bindings = $this->handler->apply($qb, $filters, $fieldMappings);

        $this->assertArrayHasKey('p0', $bindings);
        $this->assertEquals('Test', $bindings['p0']);
    }

    public function testApplyComplexFilterWithGt(): void
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->from('articles', 't0');

        $filters = ['views' => ['gt' => 100]];
        $fieldMappings = [
            'views' => ['columnName' => 'views']
        ];

        $bindings = $this->handler->apply($qb, $filters, $fieldMappings);

        $this->assertArrayHasKey('p0', $bindings);
        $this->assertEquals(100, $bindings['p0']);
    }

    public function testApplyComplexFilterWithLike(): void
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->from('articles', 't0');

        $filters = ['title' => ['like' => '%test%']];
        $fieldMappings = [
            'title' => ['columnName' => 'title']
        ];

        $bindings = $this->handler->apply($qb, $filters, $fieldMappings);

        $this->assertArrayHasKey('p0', $bindings);
        $this->assertEquals('%test%', $bindings['p0']);
    }

    public function testApplyComplexFilterWithIn(): void
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->from('articles', 't0');

        $filters = ['status' => ['in' => ['published', 'draft']]];
        $fieldMappings = [
            'status' => ['columnName' => 'status']
        ];

        $bindings = $this->handler->apply($qb, $filters, $fieldMappings);

        $this->assertArrayHasKey('p0_0', $bindings);
        $this->assertArrayHasKey('p0_1', $bindings);
        $this->assertEquals('published', $bindings['p0_0']);
        $this->assertEquals('draft', $bindings['p0_1']);
    }

    public function testApplyMultipleFilters(): void
    {
        $qb = $this->conn->createQueryBuilder();
        $qb->from('articles', 't0');

        $filters = [
            'title' => ['like' => '%test%'],
            'views' => ['gt' => 100],
            'status' => 'published'
        ];
        $fieldMappings = [
            'title' => ['columnName' => 'title'],
            'views' => ['columnName' => 'views'],
            'status' => ['columnName' => 'status']
        ];

        $bindings = $this->handler->apply($qb, $filters, $fieldMappings);

        $this->assertCount(3, $bindings);
        $this->assertArrayHasKey('p0', $bindings);
        $this->assertArrayHasKey('p1', $bindings);
        $this->assertArrayHasKey('p2', $bindings);
    }
}
