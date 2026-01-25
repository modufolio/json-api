<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use Modufolio\JsonApi\Document\ResourceObject;
use PHPUnit\Framework\TestCase;

class JsonApiLinksTest extends TestCase
{
    public function testResourceObjectContainsSelfLink(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('title', 'Test Article');
        $resource->setLinks([
            'self' => '/api/article/1'
        ]);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('self', $result['links']);
        $this->assertEquals('/api/article/1', $result['links']['self']);
    }

    public function testDocumentContainsPaginationLinks(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => '/api/articles?page[number]=2&page[size]=25',
            'first' => '/api/articles?page[number]=1&page[size]=25',
            'last' => '/api/articles?page[number]=10&page[size]=25',
            'prev' => '/api/articles?page[number]=1&page[size]=25',
            'next' => '/api/articles?page[number]=3&page[size]=25',
        ]);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('self', $result['links']);
        $this->assertArrayHasKey('first', $result['links']);
        $this->assertArrayHasKey('last', $result['links']);
        $this->assertArrayHasKey('prev', $result['links']);
        $this->assertArrayHasKey('next', $result['links']);
    }

    public function testFirstPageHasNoPrevLink(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => '/api/articles?page[number]=1&page[size]=25',
            'first' => '/api/articles?page[number]=1&page[size]=25',
            'last' => '/api/articles?page[number]=10&page[size]=25',
            'prev' => null,
            'next' => '/api/articles?page[number]=2&page[size]=25',
        ]);

        $result = $document->jsonSerialize();

        $this->assertNull($result['links']['prev']);
        $this->assertNotNull($result['links']['next']);
    }

    public function testLastPageHasNoNextLink(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => '/api/articles?page[number]=10&page[size]=25',
            'first' => '/api/articles?page[number]=1&page[size]=25',
            'last' => '/api/articles?page[number]=10&page[size]=25',
            'prev' => '/api/articles?page[number]=9&page[size]=25',
            'next' => null,
        ]);

        $result = $document->jsonSerialize();

        $this->assertNotNull($result['links']['prev']);
        $this->assertNull($result['links']['next']);
    }

    public function testRelationshipContainsLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToOneRelationship(
            'author',
            new ResourceIdentifierObject('people', '9'),
            [
                'self' => '/api/article/1/relationships/author',
                'related' => '/api/article/1/author'
            ]
        );

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('author', $result['relationships']);
        $this->assertArrayHasKey('links', $result['relationships']['author']);
        $this->assertEquals('/api/article/1/relationships/author', $result['relationships']['author']['links']['self']);
        $this->assertEquals('/api/article/1/author', $result['relationships']['author']['links']['related']);
    }

    public function testToManyRelationshipContainsLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToManyRelationship(
            'comments',
            [],
            [
                'self' => '/api/article/1/relationships/comments',
                'related' => '/api/article/1/comments'
            ]
        );

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('comments', $result['relationships']);
        $this->assertArrayHasKey('links', $result['relationships']['comments']);
        $this->assertEquals('/api/article/1/relationships/comments', $result['relationships']['comments']['links']['self']);
        $this->assertEquals('/api/article/1/comments', $result['relationships']['comments']['links']['related']);
    }

    public function testLinksCanBeComplex(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setLinks([
            'self' => '/api/article/1',
            'related' => [
                'href' => '/api/article/1/author',
                'meta' => [
                    'count' => 1
                ]
            ]
        ]);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertIsArray($result['links']['related']);
        $this->assertEquals('/api/article/1/author', $result['links']['related']['href']);
        $this->assertEquals(['count' => 1], $result['links']['related']['meta']);
    }

    public function testPaginationLinksIncludeQueryParameters(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => '/api/articles?filter[status]=published&sort=-created_at&page[number]=2&page[size]=10',
            'first' => '/api/articles?filter[status]=published&sort=-created_at&page[number]=1&page[size]=10',
            'last' => '/api/articles?filter[status]=published&sort=-created_at&page[number]=5&page[size]=10',
            'prev' => '/api/articles?filter[status]=published&sort=-created_at&page[number]=1&page[size]=10',
            'next' => '/api/articles?filter[status]=published&sort=-created_at&page[number]=3&page[size]=10',
        ]);

        $result = $document->jsonSerialize();

        $this->assertStringContainsString('filter[status]=published', $result['links']['self']);
        $this->assertStringContainsString('sort=-created_at', $result['links']['self']);
        $this->assertStringContainsString('page[number]=2', $result['links']['self']);
    }

    public function testResourceWithMultipleLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setLinks([
            'self' => '/api/article/1',
            'canonical' => 'https://example.com/articles/json-api-best-practices',
            'alternate' => '/api/v2/article/1',
        ]);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertCount(3, $result['links']);
        $this->assertEquals('/api/article/1', $result['links']['self']);
        $this->assertEquals('https://example.com/articles/json-api-best-practices', $result['links']['canonical']);
        $this->assertEquals('/api/v2/article/1', $result['links']['alternate']);
    }

    public function testEmptyLinksObjectNotIncluded(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setAttributes(['title' => 'Test']);

        $result = $resource->jsonSerialize();

        $this->assertArrayNotHasKey('links', $result);
    }

    public function testDocumentSelfLinkMatchesCurrentRequest(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => '/api/articles?page[number]=1&page[size]=25&include=author'
        ]);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertStringContainsString('page[number]=1', $result['links']['self']);
        $this->assertStringContainsString('include=author', $result['links']['self']);
    }
}
