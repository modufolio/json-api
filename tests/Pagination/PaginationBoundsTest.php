<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Pagination;

use Modufolio\JsonApi\JsonApiSerializer;
use Modufolio\JsonApi\Pagination\JsonApiPaginator;
use PHPUnit\Framework\TestCase;

/**
 * The page arithmetic divides by the page size, so a zero reaching it is a
 * DivisionByZeroError out of a serializer rather than a rejected request.
 * JsonApiUrlParser clamps what it parses; these are the paths that build
 * pagination by hand.
 */
class PaginationBoundsTest extends TestCase
{
    public function testAZeroPageSizeDoesNotDivideByZero(): void
    {
        $meta = (new JsonApiPaginator())->getMetadata(total: 10, page: 1, size: 0);

        $this->assertSame(10, $meta['last_page']);
        $this->assertSame(1, $meta['per_page']);
    }

    public function testANegativePageIsTreatedAsTheFirst(): void
    {
        $meta = (new JsonApiPaginator())->getMetadata(total: 10, page: -3, size: 5);

        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(1, $meta['from']);
    }

    public function testPageInfoIsGuardedTheSameWay(): void
    {
        $info = (new JsonApiPaginator())->getPageInfo(total: 10, page: 1, size: 0);

        $this->assertSame(10, $info['last_page']);
        $this->assertTrue($info['has_next']);
    }

    public function testSerializingACollectionSurvivesAZeroPageSize(): void
    {
        $response = JsonApiSerializer::serializeCollection([], 0, 1, 0);

        $this->assertSame(1, $response['meta']['per_page']);
    }

    /**
     * An empty collection still has a first page. Linking to page 0 would hand
     * the client a URL JsonApiUrlParser clamps straight back to 1.
     */
    public function testTheLastLinkOfAnEmptyCollectionIsPageOne(): void
    {
        $response = JsonApiSerializer::serializeCollection(
            [],
            total: 0,
            currentPage: 1,
            perPage: 10,
            baseUrl: '/contacts',
        );

        $this->assertStringContainsString('page[number]=1', $response['links']['last']);
        $this->assertStringNotContainsString('page[number]=0', $response['links']['last']);
    }
}
