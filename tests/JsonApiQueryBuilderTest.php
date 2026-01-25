<?php

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiQueryBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

class JsonApiQueryBuilderTest extends TestCase
{
    private function createQueryBuilder(): JsonApiQueryBuilder
    {
        $config = [
            'App\\Entity\\Account' => [
                'resource_key' => 'account',
                'fields' => ['id', 'name', 'created_at'],
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
        $metadata->method('getFieldNames')->willReturn(['id', 'name', 'created_at']);
        $metadata->method('hasField')->willReturnCallback(fn ($field) => in_array($field, ['id', 'name', 'created_at']));
        $metadata->method('hasAssociation')->willReturnCallback(fn ($field) => $field === 'organizations');
        $metadata->method('getAssociationMapping')->willReturnCallback(fn ($field) => $metadata->associationMappings[$field]);
        $metadata->method('getAssociationTargetClass')->willReturnCallback(fn ($field) => $metadata->associationMappings[$field]['targetEntity']);
        $em->method('getClassMetadata')->willReturn($metadata);

        return new JsonApiQueryBuilder($config, $em, $conn, 'App\\Entity\\Account');
    }

    public function testBuildUriWithComplexQuery(): void
    {
        $api = $this->createQueryBuilder();
        $uri = $api
            ->fields(['id', 'name'])
            ->filter(['name' => ['like' => '%Test%']])
            ->include(['organizations'])
            ->sort(['name', '-created_at'])
            ->group('name')
            ->having('COUNT(*) > :count', ['count' => 1])
            ->page(2, 10)
            ->operation('index')
            ->buildUri();

        $expected = '/account?fields[account]=id,name&include=organizations&filter[name][like]=%25Test%25&group=t0.name&having=COUNT(*)%20>%201&sort=name,-created_at&page[number]=2&page[size]=10';
        $this->assertEquals($expected, $uri);
    }
}
