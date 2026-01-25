<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Pagination;

use Modufolio\JsonApi\Pagination\JsonApiPaginator;
use PHPUnit\Framework\TestCase;

class JsonApiPaginatorTest extends TestCase
{
    private JsonApiPaginator $paginator;

    protected function setUp(): void
    {
        $this->paginator = new JsonApiPaginator();
    }

    public function testGetMetadataReturnsCorrectPaginationInfo(): void
    {
        $metadata = $this->paginator->getMetadata(100, 2, 25);

        $this->assertEquals(100, $metadata['total']);
        $this->assertEquals(25, $metadata['per_page']);
        $this->assertEquals(2, $metadata['current_page']);
        $this->assertEquals(4, $metadata['last_page']);
        $this->assertEquals(26, $metadata['from']);
        $this->assertEquals(50, $metadata['to']);
    }

    public function testGetMetadataForFirstPage(): void
    {
        $metadata = $this->paginator->getMetadata(100, 1, 25);

        $this->assertEquals(1, $metadata['from']);
        $this->assertEquals(25, $metadata['to']);
    }

    public function testGetMetadataForLastPage(): void
    {
        $metadata = $this->paginator->getMetadata(100, 4, 25);

        $this->assertEquals(76, $metadata['from']);
        $this->assertEquals(100, $metadata['to']);
    }

    public function testGetMetadataWithEmptyCollection(): void
    {
        $metadata = $this->paginator->getMetadata(0, 1, 25);

        $this->assertEquals(0, $metadata['total']);
        $this->assertEquals(0, $metadata['from']);
        $this->assertEquals(0, $metadata['to']);
        $this->assertEquals(0, $metadata['last_page']);
    }

    public function testGetPageInfoForFirstPage(): void
    {
        $pageInfo = $this->paginator->getPageInfo(100, 1, 25);

        $this->assertFalse($pageInfo['has_prev']);
        $this->assertTrue($pageInfo['has_next']);
        $this->assertEquals(1, $pageInfo['first_page']);
        $this->assertEquals(4, $pageInfo['last_page']);
        $this->assertNull($pageInfo['prev_page']);
        $this->assertEquals(2, $pageInfo['next_page']);
    }

    public function testGetPageInfoForMiddlePage(): void
    {
        $pageInfo = $this->paginator->getPageInfo(100, 2, 25);

        $this->assertTrue($pageInfo['has_prev']);
        $this->assertTrue($pageInfo['has_next']);
        $this->assertEquals(1, $pageInfo['prev_page']);
        $this->assertEquals(3, $pageInfo['next_page']);
    }

    public function testGetPageInfoForLastPage(): void
    {
        $pageInfo = $this->paginator->getPageInfo(100, 4, 25);

        $this->assertTrue($pageInfo['has_prev']);
        $this->assertFalse($pageInfo['has_next']);
        $this->assertEquals(3, $pageInfo['prev_page']);
        $this->assertNull($pageInfo['next_page']);
    }

    public function testSetDefaultPageSize(): void
    {
        $this->paginator->setDefaultPageSize(50);
        $this->assertEquals(50, $this->paginator->getDefaultPageSize());
    }

    public function testSetMaxPageSize(): void
    {
        $this->paginator->setMaxPageSize(200);
        $this->assertEquals(200, $this->paginator->getMaxPageSize());
    }

    public function testDefaultPageSizeIsPositive(): void
    {
        $this->paginator->setDefaultPageSize(-10);
        $this->assertEquals(1, $this->paginator->getDefaultPageSize());
    }

    public function testMaxPageSizeIsPositive(): void
    {
        $this->paginator->setMaxPageSize(-10);
        $this->assertEquals(1, $this->paginator->getMaxPageSize());
    }
}
