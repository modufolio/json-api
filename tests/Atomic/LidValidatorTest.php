<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Atomic;

use Modufolio\JsonApi\Atomic\LidValidator;
use Modufolio\JsonApi\Atomic\OperationsDocument;
use Modufolio\JsonApi\Exception\LidConflict;
use Modufolio\JsonApi\Exception\LidUnresolved;
use PHPUnit\Framework\TestCase;

/**
 * Local identifiers are checked against declaration order before anything
 * runs, with a pointer to the exact reference at fault.
 */
class LidValidatorTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $operations
     */
    private function validate(array $operations): void
    {
        (new LidValidator())->validate(OperationsDocument::parse(['atomic:operations' => $operations]));
    }

    public function testDeclarationsInOrderPass(): void
    {
        $this->validate([
            ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'a']],
            ['op' => 'add', 'data' => ['type' => 'articles', 'lid' => 'x', 'relationships' => [
                'author' => ['data' => ['type' => 'authors', 'lid' => 'a']],
                'tags' => ['data' => [['type' => 'tags', 'id' => '1'], ['type' => 'tags', 'id' => '2']]],
            ]]],
            ['op' => 'update', 'ref' => ['type' => 'articles', 'lid' => 'x'], 'data' => ['type' => 'articles', 'lid' => 'x']],
            ['op' => 'update', 'ref' => ['type' => 'articles', 'lid' => 'x', 'relationship' => 'author'], 'data' => ['type' => 'authors', 'lid' => 'a']],
            ['op' => 'remove', 'ref' => ['type' => 'authors', 'lid' => 'a']],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testSameLidForTwoTypesIsFine(): void
    {
        $this->validate([
            ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'tmp']],
            ['op' => 'add', 'data' => ['type' => 'articles', 'lid' => 'tmp']],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testDuplicateDeclarationIsA400AtTheSecondLid(): void
    {
        try {
            $this->validate([
                ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'a']],
                ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'a']],
            ]);
            $this->fail('Expected LidConflict');
        } catch (LidConflict $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame(['pointer' => '/atomic:operations/1/data/lid'], $e->getSource());
        }
    }

    public function testReferenceBeforeDeclarationIsA400AtTheRef(): void
    {
        try {
            $this->validate([
                ['op' => 'remove', 'ref' => ['type' => 'authors', 'lid' => 'a']],
                ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'a']],
            ]);
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(['pointer' => '/atomic:operations/0/ref/lid'], $e->getSource());
        }
    }

    /**
     * "Local ID cannot be both defined and used within the same operation."
     */
    public function testSelfReferenceWithinTheDeclaringOperationIsRefused(): void
    {
        try {
            $this->validate([
                ['op' => 'add', 'data' => ['type' => 'people', 'lid' => 'p', 'relationships' => [
                    'parent' => ['data' => ['type' => 'people', 'lid' => 'p']],
                ]]],
            ]);
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(['pointer' => '/atomic:operations/0/data/relationships/parent/data'], $e->getSource());
        }
    }

    public function testDanglingLidInAToManyListPointsAtTheItem(): void
    {
        try {
            $this->validate([
                ['op' => 'add', 'data' => ['type' => 'tags', 'lid' => 't1']],
                ['op' => 'add', 'data' => ['type' => 'articles', 'relationships' => [
                    'tags' => ['data' => [['type' => 'tags', 'lid' => 't1'], ['type' => 'tags', 'lid' => 't2']]],
                ]]],
            ]);
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(['pointer' => '/atomic:operations/1/data/relationships/tags/data/1'], $e->getSource());
        }
    }

    public function testDanglingLidInARelationshipOperationPointsAtData(): void
    {
        try {
            $this->validate([
                ['op' => 'update', 'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'author'], 'data' => ['type' => 'authors', 'lid' => 'nope']],
            ]);
            $this->fail('Expected LidUnresolved');
        } catch (LidUnresolved $e) {
            $this->assertSame(['pointer' => '/atomic:operations/0/data'], $e->getSource());
        }
    }

    public function testLidWrongTypeIsUnresolved(): void
    {
        $this->expectException(LidUnresolved::class);

        $this->validate([
            ['op' => 'add', 'data' => ['type' => 'authors', 'lid' => 'a']],
            ['op' => 'remove', 'ref' => ['type' => 'articles', 'lid' => 'a']],
        ]);
    }
}
