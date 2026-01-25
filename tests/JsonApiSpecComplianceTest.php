<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\Document\ErrorObject;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use Modufolio\JsonApi\Document\ResourceObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JSON:API specification compliance
 * @see https://jsonapi.org/format/
 */
class JsonApiSpecComplianceTest extends TestCase
{
    /**
     * A JSON:API document MUST contain at least one of the following top-level members: data, errors, meta
     */
    public function testDocumentContainsAtLeastOneTopLevelMember(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);

        $result = $document->jsonSerialize();

        $hasRequiredMember = isset($result['data']) || isset($result['errors']) || isset($result['meta']);
        $this->assertTrue($hasRequiredMember);
    }

    /**
     * The members data and errors MUST NOT coexist in the same document
     */
    public function testDataAndErrorsCannotCoexist(): void
    {
        $this->expectException(\LogicException::class);

        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setErrors([new ErrorObject()]);
    }

    /**
     * A document MAY contain the jsonapi member
     */
    public function testDocumentMayContainJsonApiMember(): void
    {
        $document = new JsonApiDocument();
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('jsonapi', $result);
    }

    /**
     * If present, the jsonapi object MAY contain a version member
     */
    public function testJsonApiObjectContainsVersionMember(): void
    {
        $document = new JsonApiDocument();
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('version', $result['jsonapi']);
        $this->assertEquals('1.1', $result['jsonapi']['version']);
    }

    /**
     * Resource objects MUST contain at least type and id members (except when the resource is being created)
     */
    public function testResourceObjectContainsTypeAndId(): void
    {
        $resource = new ResourceObject('article', '1');
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('id', $result);
    }

    /**
     * The values of type members MUST be strings
     */
    public function testResourceTypeIsString(): void
    {
        $resource = new ResourceObject('article', '1');
        $result = $resource->jsonSerialize();

        $this->assertIsString($result['type']);
    }

    /**
     * The values of id members MUST be strings
     */
    public function testResourceIdIsString(): void
    {
        $resource = new ResourceObject('article', '1');
        $result = $resource->jsonSerialize();

        $this->assertIsString($result['id']);
    }

    /**
     * A resource object MAY contain lid to identify a resource by a locally-unique ID
     */
    public function testResourceMayContainLid(): void
    {
        $resource = new ResourceObject('article');
        $resource->setLid('temp-1');
        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('lid', $result);
        $this->assertEquals('temp-1', $result['lid']);
    }

    /**
     * A resource cannot have both an id and a lid member
     */
    public function testResourceCannotHaveBothIdAndLid(): void
    {
        $this->expectException(\LogicException::class);

        $resource = new ResourceObject('article', '1');
        $resource->setLid('temp-1');
    }

    /**
     * The value of the attributes key MUST be an object (an "attributes object")
     */
    public function testAttributesIsArray(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setAttributes(['title' => 'Test']);
        $result = $resource->jsonSerialize();

        $this->assertIsArray($result['attributes']);
    }

    /**
     * Fields for a resource object MUST share a common namespace with relationships and cannot conflict
     */
    public function testCannotUseReservedKeywordsInAttributes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $resource = new ResourceObject('article', '1');
        $resource->setAttribute('type', 'value');
    }

    /**
     * Error objects MUST be returned as an array keyed by errors in the top level of a JSON:API document
     */
    public function testErrorsAreInTopLevelArray(): void
    {
        $document = new JsonApiDocument();
        $error = new ErrorObject();
        $error->setStatus(404);

        $document->setErrors([$error]);
        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['errors']);
    }

    /**
     * Error object status member: the HTTP status code applicable to this problem, expressed as a string value
     */
    public function testErrorStatusIsString(): void
    {
        $error = new ErrorObject();
        $error->setStatus(404);
        $result = $error->jsonSerialize();

        $this->assertIsString($result['status']);
        $this->assertEquals('404', $result['status']);
    }

    /**
     * If a document does not contain a top-level data key, the included member MUST NOT be present
     */
    public function testIncludedRequiresData(): void
    {
        $this->expectException(\LogicException::class);

        $document = new JsonApiDocument();
        $document->setIncluded([new ResourceObject('article', '1')]);
    }

    /**
     * Resource linkage in a compound document allows a client to link together all of the included resource objects
     */
    public function testResourceLinkageWithRelationships(): void
    {
        $resource = new ResourceObject('article', '1');
        $author = (new ResourceObject('people', '9'))->setAttribute('name', 'Dan');

        $resource->setToOneRelationship('author', new ResourceIdentifierObject('people', '9'));

        $document = new JsonApiDocument();
        $document->setData($resource);
        $document->setIncluded([$author]);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('included', $result);
        $this->assertArrayHasKey('relationships', $result['data']->jsonSerialize());
    }

    /**
     * Relationship objects MUST contain at least one of: links, data, meta
     */
    public function testRelationshipContainsRequiredMembers(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToOneRelationship('author', new ResourceIdentifierObject('people', '9'));

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('data', $result['relationships']['author']);
    }

    /**
     * Test that sparse fieldsets can be implemented (attributes can be omitted)
     */
    public function testSparseFieldsets(): void
    {
        $resource = new ResourceObject('article', '1');
        // Don't set any attributes - this should be valid
        $result = $resource->jsonSerialize();

        $this->assertArrayNotHasKey('attributes', $result);
    }

    /**
     * Test that empty to-many relationships are represented as empty arrays
     */
    public function testEmptyToManyRelationship(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToManyRelationship('comments', []);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('comments', $result['relationships']);
        $this->assertIsArray($result['relationships']['comments']['data']);
        $this->assertEmpty($result['relationships']['comments']['data']);
    }

    /**
     * Test that null to-one relationships are represented as null
     */
    public function testNullToOneRelationship(): void
    {
        $resource = new ResourceObject('article', '1');
        $resource->setToOneRelationship('author', null);

        $result = $resource->jsonSerialize();

        $this->assertArrayHasKey('relationships', $result);
        $this->assertArrayHasKey('author', $result['relationships']);
        $this->assertNull($result['relationships']['author']['data']);
    }

    /**
     * Test meta objects can contain any non-standard information
     */
    public function testMetaObjectsCanContainArbitraryData(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setMeta([
            'copyright' => 'Copyright 2024',
            'authors' => ['John', 'Jane'],
            'nested' => [
                'key' => 'value',
            ],
        ]);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['meta']);
        $this->assertArrayHasKey('copyright', $result['meta']);
        $this->assertArrayHasKey('nested', $result['meta']);
    }

    /**
     * Test links objects can contain self, related, and pagination links
     */
    public function testLinksObjectStructure(): void
    {
        $document = new JsonApiDocument();
        $document->setData([]);
        $document->setLinks([
            'self' => 'http://example.com/articles',
            'next' => 'http://example.com/articles?page=2',
            'prev' => null,
        ]);

        $result = $document->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('self', $result['links']);
        $this->assertArrayHasKey('next', $result['links']);
    }
}
