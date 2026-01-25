<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use Modufolio\JsonApi\Document\ResourceObject;
use PHPUnit\Framework\TestCase;

class ResourceObjectTest extends TestCase
{
    public function testCreateResourceWithTypeAndId(): void
    {
        $resource = new ResourceObject('article', '1');
        $result = $resource->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('1', $result['id']);
    }

    public function testCreateResourceWithTypeOnly(): void
    {
        $resource = new ResourceObject('article');
        $result = $resource->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('lid', $result);
    }

    public function testSetLid(): void
    {
        $resource = new ResourceObject('article');
        $resource->setLid('temp-1');
        $result = $resource->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('temp-1', $result['lid']);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function testCannotSetLidWhenIdPresent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot set lid when id is present');

        $resource = new ResourceObject('article', '1');
        $resource->setLid('temp-1');
    }

    public function testSetAttributes(): void
    {
        $resource = new ResourceObject('article', '1');
        $attributes = [
            'title' => 'Test Article',
            'body' => 'Test body',
        ];

        $resource->setAttributes($attributes);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('attributes', $result);
        $this->assertEquals($attributes, $result['attributes']);
    }

    public function testSetAttribute(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('title', 'Test Article');
        $resource->setAttribute('body', 'Test body');

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('attributes', $result);
        $this->assertEquals('Test Article', $result['attributes']['title']);
        $this->assertEquals('Test body', $result['attributes']['body']);
    }

    public function testCannotUseReservedKeywordsAsAttributeName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot use reserved keyword 'type' as an attribute name");

        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('type', 'value');
    }

    public function testCannotUseIdAsAttributeName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot use reserved keyword 'id' as an attribute name");

        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('id', 'value');
    }

    public function testCannotUseLidAsAttributeName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot use reserved keyword 'lid' as an attribute name");

        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('lid', 'value');
    }

    public function testSetToOneRelationship(): void
    {
        $resource = new ResourceObject('article', '1');
        $author = new ResourceIdentifierObject('people', '9');

        $resource->setToOneRelationship('author', $author);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('author', $result['relationships']);
        $this->assertArrayHasKey('data', $result['relationships']['author']);
        $this->assertInstanceOf(ResourceIdentifierObject::class, $result['relationships']['author']['data']);
    }

    public function testSetToOneRelationshipWithNull(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToOneRelationship('author', null);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('author', $result['relationships']);
        $this->assertNull($result['relationships']['author']['data']);
    }

    public function testSetToOneRelationshipWithLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $author = new ResourceIdentifierObject('people', '9');
        $links = [
            'self' => '/articles/1/relationships/author',
            'related' => '/articles/1/author',
        ];

        $resource->setToOneRelationship('author', $author, $links);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('links', $result['relationships']['author']);
        $this->assertEquals($links, $result['relationships']['author']['links']);
    }

    public function testCannotUseReservedKeywordAsRelationshipName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot use reserved keyword 'type' as a relationship name");

        $resource = new ResourceObject('article', '1');
        $resource->setToOneRelationship('type', new ResourceIdentifierObject('people', '1'));
    }

    public function testSetToManyRelationship(): void
    {
        $resource = new ResourceObject('article', '1');
        $comments = [
            new ResourceIdentifierObject('comment', '5'),
            new ResourceIdentifierObject('comment', '12'),
        ];

        $resource->setToManyRelationship('comments', $comments);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('comments', $result['relationships']);
        $this->assertIsArray($result['relationships']['comments']['data']);
        $this->assertCount(2, $result['relationships']['comments']['data']);
    }

    public function testSetToManyRelationshipWithLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $comments = [];
        $links = [
            'self' => '/articles/1/relationships/comments',
            'related' => '/articles/1/comments',
        ];

        $resource->setToManyRelationship('comments', $comments, $links);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('links', $result['relationships']['comments']);
        $this->assertEquals($links, $result['relationships']['comments']['links']);
    }

    public function testSetRelationships(): void
    {
        $resource = new ResourceObject('article', '1');
        $relationships = [
            'author' => [
                'data' => new ResourceIdentifierObject('people', '9'),
            ],
            'comments' => [
                'data' => [
                    new ResourceIdentifierObject('comment', '5'),
                ],
            ],
        ];

        $resource->setRelationships($relationships);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertEquals($relationships, $result['relationships']);
    }

    public function testSetLinks(): void
    {
        $resource = new ResourceObject('article', '1');
        $links = [
            'self' => 'http://example.com/articles/1',
        ];

        $resource->setLinks($links);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertEquals($links, $result['links']);
    }

    public function testSetMeta(): void
    {
        $resource = new ResourceObject('article', '1');
        $meta = [
            'created' => '2024-01-01',
        ];

        $resource->setMeta($meta);
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testResourceWithoutOptionalMembers(): void
    {
        $resource = new ResourceObject('article', '1');
        $result = $resource->jsonSerialize();

        $this->assertArrayNotHasKey('attributes', $result);
        $this->assertArrayNotHasKey('relationships', $result);
        $this->assertArrayNotHasKey('links', $result);
        $this->assertArrayNotHasKey('meta', $result);
    }

    public function testComplexResource(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('title', 'JSON:API paints my bikeshed!');
        $resource->setAttribute('body', 'The shortest article. Ever.');
        $resource->setToOneRelationship('author', new ResourceIdentifierObject('people', '9'));
        $resource->setToManyRelationship('comments', [
            new ResourceIdentifierObject('comment', '5'),
            new ResourceIdentifierObject('comment', '12'),
        ]);
        $resource->setLinks(['self' => 'http://example.com/articles/1']);
        $resource->setMeta(['created' => '2024-01-01']);

        $result = $resource->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('1', $result['id']);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertCount(2, $result['attributes']);
        $this->assertCount(2, $result['relationships']);
    }
}
