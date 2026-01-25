<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Modufolio\JsonApi\Document\ErrorObject;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use Modufolio\JsonApi\Document\ResourceObject;
use PHPUnit\Framework\TestCase;

class JsonApiDocumentTest extends TestCase
{
    public function testDocumentIncludesJsonApiVersion(): void
    {
        $document = new JsonApiDocument();
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('jsonapi', $result);
        $this->assertEquals(['version' => '1.1'], $result['jsonapi']);
    }

    public function testSetDataWithSingleResource(): void
    {
        $document = new JsonApiDocument();
        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('title', 'Test Article');

        $document->setData($resource);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertInstanceOf(ResourceObject::class, $result['data']);
    }

    public function testSetDataWithMultipleResources(): void
    {
        $document = new JsonApiDocument();
        $resources = [
            new ResourceObject('article', '1'),
            new ResourceObject('article', '2'),
        ];

        $document->setData($resources);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);
    }

    public function testSetDataWithNull(): void
    {
        $document = new JsonApiDocument();
        $document->setData(null);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertNull($result['data']);
    }

    public function testSetDataWithResourceIdentifiers(): void
    {
        $document = new JsonApiDocument();
        $identifiers = [
            new ResourceIdentifierObject('article', '1'),
            new ResourceIdentifierObject('article', '2'),
        ];

        $document->setData($identifiers);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);
    }

    public function testSetErrors(): void
    {
        $document = new JsonApiDocument();
        $error = new ErrorObject();
        $error->setStatus(404);
        $error->setTitle('Not Found');

        $document->setErrors([$error]);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['errors']);
        $this->assertCount(1, $result['errors']);
    }

    public function testCannotSetBothDataAndErrors(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot include both data and errors in a JSON:API document');

        $document = new JsonApiDocument();
        $document->setData(new ResourceObject('article', '1'));
        $document->setErrors([new ErrorObject()]);
    }

    public function testCannotSetDataAfterErrors(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot include both data and errors in a JSON:API document');

        $document = new JsonApiDocument();
        $document->setErrors([new ErrorObject()]);
        $document->setData(new ResourceObject('article', '1'));
    }

    public function testSetMeta(): void
    {
        $document = new JsonApiDocument();
        $meta = [
            'total' => 100,
            'page' => 1,
        ];

        $document->setMeta($meta);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testSetIncluded(): void
    {
        $document = new JsonApiDocument();
        $resource = new ResourceObject('article', '1');
        $included = [
            new ResourceObject('author', '1'),
            new ResourceObject('comment', '1'),
        ];

        $document->setData($resource);
        $document->setIncluded($included);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('included', $result);
        $this->assertIsArray($result['included']);
        $this->assertCount(2, $result['included']);
    }

    public function testCannotSetIncludedWithoutData(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot include resources without primary data');

        $document = new JsonApiDocument();
        $included = [new ResourceObject('author', '1')];
        $document->setIncluded($included);
    }

    public function testSetLinks(): void
    {
        $document = new JsonApiDocument();
        $links = [
            'self' => 'http://example.com/articles',
            'next' => 'http://example.com/articles?page=2',
        ];

        $document->setLinks($links);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertEquals($links, $result['links']);
    }

    public function testSetJsonApi(): void
    {
        $document = new JsonApiDocument();
        $jsonapi = [
            'version' => '1.0',
            'meta' => ['custom' => 'value'],
        ];

        $document->setJsonApi($jsonapi);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('jsonapi', $result);
        $this->assertEquals($jsonapi, $result['jsonapi']);
    }

    public function testToArray(): void
    {
        $document = new JsonApiDocument();
        $document->setData(new ResourceObject('article', '1'));
        $document->setMeta(['total' => 1]);

        $result = $document->toArray();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('jsonapi', $result);
    }

    public function testComplexDocument(): void
    {
        $document = new JsonApiDocument();

        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('title', 'JSON:API paints my bikeshed!');
        $resource->setToOneRelationship('author', new ResourceIdentifierObject('people', '9'));

        $included = [
            (new ResourceObject('people', '9'))
                ->setAttribute('first_name', 'Dan')
                ->setAttribute('last_name', 'Gebhardt'),
        ];

        $document->setData($resource);
        $document->setIncluded($included);
        $document->setLinks(['self' => 'http://example.com/articles/1']);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('jsonapi', $result);
        $this->assertCount(1, $result['included']);
    }
}
