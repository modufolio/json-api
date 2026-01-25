<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiSerializer;
use PHPUnit\Framework\TestCase;

class JsonApiSerializerTest extends TestCase
{
    public function testSerializeResource(): void
    {
        $data = [
            'type' => 'article',
            'id' => '1',
            'attributes' => [
                'title' => 'Test Article',
            ],
        ];

        $result = JsonApiSerializer::serializeResource($data);

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals($data, $result['data']);
    }

    public function testSerializeResourceWithMeta(): void
    {
        $data = ['type' => 'article', 'id' => '1'];
        $meta = ['created_at' => '2024-01-01'];

        $result = JsonApiSerializer::serializeResource($data, null, $meta);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testSerializeResourceWithIncluded(): void
    {
        $data = ['type' => 'article', 'id' => '1'];
        $included = [
            ['type' => 'people', 'id' => '9'],
        ];

        $result = JsonApiSerializer::serializeResource($data, null, [], $included);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertEquals($included, $result['included']);
    }

    public function testSerializeCollection(): void
    {
        $data = [
            ['type' => 'article', 'id' => '1'],
            ['type' => 'article', 'id' => '2'],
        ];

        $result = JsonApiSerializer::serializeCollection($data, 2, 1, 25);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($data, $result['data']);
    }

    public function testSerializeCollectionWithPaginationMeta(): void
    {
        $data = [
            ['type' => 'article', 'id' => '1'],
            ['type' => 'article', 'id' => '2'],
        ];

        $result = JsonApiSerializer::serializeCollection($data, 50, 2, 25);

        $this->assertEquals(50, $result['meta']['total']);
        $this->assertEquals(25, $result['meta']['per_page']);
        $this->assertEquals(2, $result['meta']['current_page']);
        $this->assertEquals(2, $result['meta']['last_page']);
        $this->assertEquals(26, $result['meta']['from']);
        $this->assertEquals(50, $result['meta']['to']);
    }

    public function testSerializeCollectionWithEmptyData(): void
    {
        $result = JsonApiSerializer::serializeCollection([], 0, 1, 25);

        $this->assertEquals(0, $result['meta']['total']);
        $this->assertEquals(0, $result['meta']['from']);
        $this->assertEquals(0, $result['meta']['to']);
        $this->assertEquals(0, $result['meta']['last_page']);
    }

    public function testSerializeCollectionWithPaginationLinks(): void
    {
        $data = [
            ['type' => 'article', 'id' => '1'],
        ];
        $baseUrl = 'http://example.com/articles';

        $result = JsonApiSerializer::serializeCollection($data, 50, 2, 25, null, [], [], $baseUrl);

        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('self', $result['links']);
        $this->assertArrayHasKey('first', $result['links']);
        $this->assertArrayHasKey('last', $result['links']);
        $this->assertArrayHasKey('prev', $result['links']);
        $this->assertArrayHasKey('next', $result['links']);
        $this->assertStringContainsString('page[number]=2', $result['links']['self']);
    }

    public function testSerializeCollectionFirstPageLinks(): void
    {
        $data = [['type' => 'article', 'id' => '1']];
        $baseUrl = 'http://example.com/articles';

        $result = JsonApiSerializer::serializeCollection($data, 50, 1, 25, null, [], [], $baseUrl);

        $this->assertNull($result['links']['prev']);
        $this->assertNotNull($result['links']['next']);
    }

    public function testSerializeCollectionLastPageLinks(): void
    {
        $data = [['type' => 'article', 'id' => '1']];
        $baseUrl = 'http://example.com/articles';

        $result = JsonApiSerializer::serializeCollection($data, 50, 2, 25, null, [], [], $baseUrl);

        $this->assertNotNull($result['links']['prev']);
        $this->assertNull($result['links']['next']);
    }

    public function testParsePaginationParamsJsonApiFormat(): void
    {
        $queryParams = [
            'page' => [
                'number' => 2,
                'size' => 50,
            ],
        ];

        $result = JsonApiSerializer::parsePaginationParams($queryParams);

        $this->assertEquals(2, $result['number']);
        $this->assertEquals(50, $result['size']);
    }

    public function testParsePaginationParamsLegacyFormat(): void
    {
        $queryParams = [
            'page' => 3,
            'per_page' => 100,
        ];

        $result = JsonApiSerializer::parsePaginationParams($queryParams);

        $this->assertEquals(3, $result['number']);
        $this->assertEquals(100, $result['size']);
    }

    public function testParsePaginationParamsDefaults(): void
    {
        $result = JsonApiSerializer::parsePaginationParams([]);

        $this->assertEquals(1, $result['number']);
        $this->assertEquals(25, $result['size']);
    }

    public function testParsePaginationParamsMaxLimit(): void
    {
        $queryParams = [
            'page' => [
                'number' => 1,
                'size' => 500, // Exceeds max of 100
            ],
        ];

        $result = JsonApiSerializer::parsePaginationParams($queryParams);

        $this->assertEquals(100, $result['size']);
    }

    public function testParsePaginationParamsMinimumValues(): void
    {
        $queryParams = [
            'page' => [
                'number' => -5,
                'size' => -10,
            ],
        ];

        $result = JsonApiSerializer::parsePaginationParams($queryParams);

        $this->assertEquals(1, $result['number']);
        $this->assertEquals(1, $result['size']);
    }

    public function testParseFilterParams(): void
    {
        $queryParams = [
            'filter' => [
                'name' => 'John',
                'age' => ['gt' => 18],
            ],
        ];

        $result = JsonApiSerializer::parseFilterParams($queryParams);

        $this->assertEquals('John', $result['name']);
        $this->assertEquals(['gt' => 18], $result['age']);
    }

    public function testParseFilterParamsEmpty(): void
    {
        $result = JsonApiSerializer::parseFilterParams([]);

        $this->assertEmpty($result);
    }

    public function testParseSortParams(): void
    {
        $queryParams = [
            'sort' => 'name,-created_at,title',
        ];

        $result = JsonApiSerializer::parseSortParams($queryParams);

        $this->assertEquals('ASC', $result['name']);
        $this->assertEquals('DESC', $result['created_at']);
        $this->assertEquals('ASC', $result['title']);
    }

    public function testParseSortParamsEmpty(): void
    {
        $result = JsonApiSerializer::parseSortParams([]);

        $this->assertEmpty($result);
    }

    public function testParseIncludeParams(): void
    {
        $queryParams = [
            'include' => 'author,comments,tags',
        ];

        $result = JsonApiSerializer::parseIncludeParams($queryParams);

        $this->assertEquals(['author', 'comments', 'tags'], $result);
    }

    public function testParseIncludeParamsEmpty(): void
    {
        $result = JsonApiSerializer::parseIncludeParams([]);

        $this->assertEmpty($result);
    }

    public function testSerializeError(): void
    {
        $result = JsonApiSerializer::serializeError(
            'Not Found',
            'The resource was not found',
            404
        );

        $this->assertArrayHasKey('errors', $result);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals('404', $result['errors'][0]['status']);
        $this->assertEquals('Not Found', $result['errors'][0]['title']);
        $this->assertEquals('The resource was not found', $result['errors'][0]['detail']);
    }

    public function testSerializeErrorWithMeta(): void
    {
        $meta = ['timestamp' => '2024-01-01'];
        $result = JsonApiSerializer::serializeError(
            'Error',
            'Something went wrong',
            500,
            $meta
        );

        $this->assertArrayHasKey('meta', $result['errors'][0]);
        $this->assertEquals($meta, $result['errors'][0]['meta']);
    }

    public function testSerializeValidationErrors(): void
    {
        $validationErrors = [
            'title' => 'Title is required',
            'email' => 'Email must be valid',
        ];

        $result = JsonApiSerializer::serializeValidationErrors($validationErrors);

        $this->assertArrayHasKey('errors', $result);
        $this->assertCount(2, $result['errors']);
        $this->assertEquals('422', $result['errors'][0]['status']);
        $this->assertEquals('Validation Error', $result['errors'][0]['title']);
        $this->assertEquals('/data/attributes/title', $result['errors'][0]['source']['pointer']);
        $this->assertEquals('/data/attributes/email', $result['errors'][1]['source']['pointer']);
    }
}
