<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Modufolio\JsonApi\Document\ErrorObject;
use PHPUnit\Framework\TestCase;

class ErrorObjectTest extends TestCase
{
    public function testSetId(): void
    {
        $error = new ErrorObject();
        $error->setId('error-123');

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('error-123', $result['id']);
    }

    public function testSetStatus(): void
    {
        $error = new ErrorObject();
        $error->setStatus(404);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('status', $result);
        $this->assertEquals('404', $result['status']);
        $this->assertIsString($result['status']);
    }

    public function testSetCode(): void
    {
        $error = new ErrorObject();
        $error->setCode('RESOURCE_NOT_FOUND');

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('code', $result);
        $this->assertEquals('RESOURCE_NOT_FOUND', $result['code']);
    }

    public function testSetTitle(): void
    {
        $error = new ErrorObject();
        $error->setTitle('Resource Not Found');

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('title', $result);
        $this->assertEquals('Resource Not Found', $result['title']);
    }

    public function testSetDetail(): void
    {
        $error = new ErrorObject();
        $error->setDetail('The requested article with id 123 was not found');

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('detail', $result);
        $this->assertEquals('The requested article with id 123 was not found', $result['detail']);
    }

    public function testSetSource(): void
    {
        $error = new ErrorObject();
        $source = [
            'pointer' => '/data/attributes/title',
        ];
        $error->setSource($source);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('source', $result);
        $this->assertEquals($source, $result['source']);
    }

    public function testSetSourceWithParameter(): void
    {
        $error = new ErrorObject();
        $source = [
            'parameter' => 'include',
        ];
        $error->setSource($source);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('source', $result);
        $this->assertEquals($source, $result['source']);
    }

    public function testSetLinks(): void
    {
        $error = new ErrorObject();
        $links = [
            'about' => 'http://example.com/docs/errors/resource-not-found',
        ];
        $error->setLinks($links);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('links', $result);
        $this->assertEquals($links, $result['links']);
    }

    public function testSetMeta(): void
    {
        $error = new ErrorObject();
        $meta = [
            'timestamp' => '2024-01-01T12:00:00Z',
        ];
        $error->setMeta($meta);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testValidationError(): void
    {
        $error = new ErrorObject();
        $error->setStatus(422);
        $error->setTitle('Validation Error');
        $error->setDetail('Title must not be empty');
        $error->setSource(['pointer' => '/data/attributes/title']);

        $result = $error->jsonSerialize();

        $this->assertEquals('422', $result['status']);
        $this->assertEquals('Validation Error', $result['title']);
        $this->assertEquals('Title must not be empty', $result['detail']);
        $this->assertEquals(['pointer' => '/data/attributes/title'], $result['source']);
    }

    public function testCompleteError(): void
    {
        $error = new ErrorObject();
        $error->setId('error-123');
        $error->setStatus(404);
        $error->setCode('RESOURCE_NOT_FOUND');
        $error->setTitle('Resource Not Found');
        $error->setDetail('The requested article was not found');
        $error->setSource(['pointer' => '/data']);
        $error->setLinks(['about' => 'http://example.com/docs/errors']);
        $error->setMeta(['timestamp' => '2024-01-01']);

        $result = $error->jsonSerialize();

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('code', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('detail', $result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('links', $result);
        $this->assertArrayHasKey('meta', $result);
    }

    public function testEmptyError(): void
    {
        $error = new ErrorObject();
        $result = $error->jsonSerialize();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
