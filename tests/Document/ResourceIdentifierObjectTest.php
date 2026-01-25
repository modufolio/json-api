<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use PHPUnit\Framework\TestCase;

class ResourceIdentifierObjectTest extends TestCase
{
    public function testCreateWithTypeAndId(): void
    {
        $identifier = new ResourceIdentifierObject('article', '1');
        $result = $identifier->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('1', $result['id']);
    }

    public function testCreateWithTypeOnly(): void
    {
        $identifier = new ResourceIdentifierObject('article', null);
        $result = $identifier->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertArrayNotHasKey('id', $result);
        $this->assertArrayNotHasKey('lid', $result);
    }

    public function testSetLid(): void
    {
        $identifier = new ResourceIdentifierObject('article', null);
        $identifier->setLid('temp-1');
        $result = $identifier->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('temp-1', $result['lid']);
        $this->assertArrayNotHasKey('id', $result);
    }

    public function testCannotSetLidWhenIdPresent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot set lid when id is present');

        $identifier = new ResourceIdentifierObject('article', '1');
        $identifier->setLid('temp-1');
    }

    public function testSetMeta(): void
    {
        $identifier = new ResourceIdentifierObject('article', '1');
        $meta = [
            'created' => '2024-01-01',
        ];

        $identifier->setMeta($meta);
        $result = $identifier->jsonSerialize();

        $this->assertArrayHasKey('meta', $result);
        $this->assertEquals($meta, $result['meta']);
    }

    public function testWithoutMeta(): void
    {
        $identifier = new ResourceIdentifierObject('article', '1');
        $result = $identifier->jsonSerialize();

        $this->assertArrayNotHasKey('meta', $result);
    }

    public function testCompleteIdentifier(): void
    {
        $identifier = new ResourceIdentifierObject('article', '1');
        $identifier->setMeta(['created' => '2024-01-01']);

        $result = $identifier->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('1', $result['id']);
        $this->assertArrayHasKey('meta', $result);
    }

    public function testIdentifierWithLidAndMeta(): void
    {
        $identifier = new ResourceIdentifierObject('article', null);
        $identifier->setLid('temp-1');
        $identifier->setMeta(['created_by' => 'client']);

        $result = $identifier->jsonSerialize();

        $this->assertEquals('article', $result['type']);
        $this->assertEquals('temp-1', $result['lid']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayNotHasKey('id', $result);
    }
}
