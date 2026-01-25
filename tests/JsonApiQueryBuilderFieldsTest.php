<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiQueryBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderFieldsTest extends TestCase
{
    private function createQueryBuilder(): JsonApiQueryBuilder
    {
        $config = [
            'App\\Entity\\Account' => [
                'resource_key' => 'account',
                'fields' => ['id', 'name', 'email', 'created_at'],
                'relationships' => ['organizations'],
                'operations' => ['index' => true, 'show' => true, 'create' => true],
            ],
        ];

        $em = $this->createMock(EntityManagerInterface::class);
        $conn = $this->createMock(Connection::class);
        $metadata = $this->createMock(ClassMetadata::class);

        $metadata->fieldMappings = [
            'id' => ['columnName' => 'id'],
            'name' => ['columnName' => 'name'],
            'email' => ['columnName' => 'email'],
            'created_at' => ['columnName' => 'created_at'],
        ];
        $metadata->associationMappings = [
            'organizations' => [
                'targetEntity' => 'App\\Entity\\Organization',
                'type' => ClassMetadata::TO_MANY,
                'joinTable' => ['name' => 'account_organization'],
            ],
        ];
        $metadata->table = ['name' => 'accounts'];
        $metadata->method('getFieldNames')->willReturn(['id', 'name', 'email', 'created_at']);
        $metadata->method('hasField')->willReturnCallback(fn ($field) => in_array($field, ['id', 'name', 'email', 'created_at']));
        $metadata->method('hasAssociation')->willReturnCallback(fn ($field) => $field === 'organizations');
        $metadata->method('getAssociationMapping')->willReturnCallback(fn ($field) => $metadata->associationMappings[$field]);
        $metadata->method('getAssociationTargetClass')->willReturnCallback(fn ($field) => $metadata->associationMappings[$field]['targetEntity']);
        $metadata->method('getTableName')->willReturn('accounts');
        $em->method('getClassMetadata')->willReturn($metadata);

        return new JsonApiQueryBuilder($config, $em, $conn, 'App\\Entity\\Account');
    }

    public function testIndexReturnsDataWithAttributes(): void
    {
        $api = $this->createQueryBuilder();

        // Mock the database result
        $mockResult = $this->createMock(Result::class);
        $mockResult->method('fetchAllAssociative')->willReturn([
            [
                'id' => 1,
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'created_at' => '2024-01-02 00:00:00',
            ],
        ]);

        // We can't fully test without mocking the query execution,
        // but we can test the transformation method directly
        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('transformRowToJsonApi');
        $method->setAccessible(true);

        $row = [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'created_at' => '2024-01-01 00:00:00',
        ];

        $result = $method->invoke($api, $row);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertEquals(1, $result['id']);
        $this->assertArrayHasKey('name', $result['attributes']);
        $this->assertArrayHasKey('email', $result['attributes']);
        $this->assertArrayHasKey('created_at', $result['attributes']);
        $this->assertEquals('John Doe', $result['attributes']['name']);
        $this->assertEquals('john@example.com', $result['attributes']['email']);
    }

    public function testTransformRowSeparatesIdFromAttributes(): void
    {
        $api = $this->createQueryBuilder();

        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('transformRowToJsonApi');
        $method->setAccessible(true);

        $row = [
            'id' => 123,
            'name' => 'Test Name',
            'email' => 'test@example.com',
        ];

        $result = $method->invoke($api, $row);

        // ID should not be in attributes
        $this->assertArrayNotHasKey('id', $result['attributes']);
        $this->assertEquals(123, $result['id']);
        $this->assertCount(2, $result['attributes']);
    }

    public function testTransformRowWithSparseFieldset(): void
    {
        $api = $this->createQueryBuilder();

        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('transformRowToJsonApi');
        $method->setAccessible(true);

        // Simulate sparse fieldset - only id and name returned
        $row = [
            'id' => 1,
            'name' => 'John Doe',
        ];

        $result = $method->invoke($api, $row);

        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['attributes']);
        $this->assertArrayHasKey('name', $result['attributes']);
        $this->assertArrayNotHasKey('email', $result['attributes']);
    }

    public function testTransformRowWithEmptyAttributes(): void
    {
        $api = $this->createQueryBuilder();

        $reflection = new \ReflectionClass($api);
        $method = $reflection->getMethod('transformRowToJsonApi');
        $method->setAccessible(true);

        // Only ID field
        $row = [
            'id' => 1,
        ];

        $result = $method->invoke($api, $row);

        $this->assertEquals(1, $result['id']);
        $this->assertIsArray($result['attributes']);
        $this->assertEmpty($result['attributes']);
    }
}
