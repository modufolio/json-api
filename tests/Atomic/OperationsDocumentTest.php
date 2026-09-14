<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Atomic;

use Modufolio\JsonApi\Atomic\Operation;
use Modufolio\JsonApi\Atomic\OperationsDocument;
use Modufolio\JsonApi\Exception\OperationMalformed;
use PHPUnit\Framework\TestCase;

/**
 * Structural validation of an `atomic:operations` document. Every failure
 * is a 400 whose pointer names the member at fault, raised before anything
 * runs.
 */
class OperationsDocumentTest extends TestCase
{
    public function testParsesTheThreeOperationKinds(): void
    {
        $operations = OperationsDocument::parse([
            'atomic:operations' => [
                ['op' => 'add', 'data' => ['type' => 'articles', 'lid' => 'a', 'attributes' => ['title' => 'x']]],
                ['op' => 'update', 'ref' => ['type' => 'articles', 'lid' => 'a'], 'data' => ['type' => 'articles', 'lid' => 'a', 'attributes' => ['title' => 'y']]],
                ['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '3'], 'meta' => ['reason' => 'spam']],
            ],
        ]);

        $this->assertCount(3, $operations);

        $this->assertSame(Operation::ADD, $operations[0]->op);
        $this->assertNull($operations[0]->ref);
        $this->assertSame('articles', $operations[0]->type());
        $this->assertSame('a', $operations[0]->dataLid());
        $this->assertSame('/atomic:operations/0', $operations[0]->pointer());

        $this->assertSame('a', $operations[1]->ref?->lid);
        $this->assertTrue($operations[1]->hasData);

        $this->assertSame('3', $operations[2]->ref?->id);
        $this->assertFalse($operations[2]->hasData);
        $this->assertSame(['reason' => 'spam'], $operations[2]->meta);
        $this->assertSame('/atomic:operations/2/ref', $operations[2]->pointer('/ref'));
    }

    public function testRelationshipRefAndHrefTargets(): void
    {
        $operations = OperationsDocument::parse([
            'atomic:operations' => [
                ['op' => 'update', 'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'author'], 'data' => null],
                ['op' => 'add', 'href' => '/articles', 'data' => ['type' => 'articles']],
            ],
        ]);

        $this->assertTrue($operations[0]->targetsRelationship());
        $this->assertSame('author', $operations[0]->ref?->relationship);
        $this->assertTrue($operations[0]->hasData);
        $this->assertNull($operations[0]->data);

        $this->assertSame('/articles', $operations[1]->href);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'no operations member' => [['data' => []], ''];
        yield 'operations beside data' => [['atomic:operations' => [], 'data' => null], '/data'];
        yield 'operations not a list' => [['atomic:operations' => ['op' => 'add']], '/atomic:operations'];
        yield 'empty operations' => [['atomic:operations' => []], '/atomic:operations'];
        yield 'operation not an object' => [['atomic:operations' => ['add']], '/atomic:operations/0'];
        yield 'unknown op' => [['atomic:operations' => [['op' => 'patch']]], '/atomic:operations/0/op'];
        yield 'ref and href' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'a', 'id' => '1'], 'href' => '/a/1']]], '/atomic:operations/0'];
        yield 'ref without type' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['id' => '1']]]], '/atomic:operations/0/ref/type'];
        yield 'ref without id or lid' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'a']]]], '/atomic:operations/0/ref'];
        yield 'ref with id and lid' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'a', 'id' => '1', 'lid' => 'x']]]], '/atomic:operations/0/ref'];
        yield 'ref with a foreign member' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'a', 'id' => '1', 'href' => 'x']]]], '/atomic:operations/0/ref/href'];
        yield 'empty href' => [['atomic:operations' => [['op' => 'add', 'href' => '', 'data' => ['type' => 'a']]]], '/atomic:operations/0/href'];
        yield 'add without a type' => [['atomic:operations' => [['op' => 'add', 'data' => ['attributes' => []]]]], '/atomic:operations/0/data'];
        yield 'update without a target' => [['atomic:operations' => [['op' => 'update', 'data' => ['type' => 'a']]]], '/atomic:operations/0'];
        yield 'remove without a target' => [['atomic:operations' => [['op' => 'remove']]], '/atomic:operations/0'];
        yield 'remove of a resource with data' => [['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'a', 'id' => '1'], 'data' => null]]], '/atomic:operations/0/data'];
        yield 'relationship op without data' => [['atomic:operations' => [['op' => 'update', 'ref' => ['type' => 'a', 'id' => '1', 'relationship' => 'r']]]], '/atomic:operations/0'];
        yield 'add with a ref but no relationship' => [['atomic:operations' => [['op' => 'add', 'ref' => ['type' => 'a', 'id' => '1'], 'data' => ['type' => 'b', 'id' => '2']]]], '/atomic:operations/0/ref'];
        yield 'meta not an object' => [['atomic:operations' => [['op' => 'add', 'data' => ['type' => 'a'], 'meta' => 'x']]], '/atomic:operations/0/meta'];
    }

    /**
     * @dataProvider malformedDocuments
     *
     * @param array<string, mixed> $payload
     */
    public function testMalformedDocumentIsA400PointingAtTheFault(array $payload, string $pointer): void
    {
        try {
            OperationsDocument::parse($payload);
            $this->fail('Expected OperationMalformed');
        } catch (OperationMalformed $e) {
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('ATOMIC_OPERATION_MALFORMED', $e->getErrorCode());
            $this->assertSame($pointer === '' ? [] : ['pointer' => $pointer], $e->getSource());
        }
    }

    public function testTooManyOperationsIsA400(): void
    {
        $payload = ['atomic:operations' => array_fill(0, 3, ['op' => 'add', 'data' => ['type' => 'a']])];

        $this->assertCount(3, OperationsDocument::parse($payload, maxOperations: 3));

        try {
            OperationsDocument::parse($payload, maxOperations: 2);
            $this->fail('Expected OperationMalformed');
        } catch (OperationMalformed $e) {
            $this->assertSame(['pointer' => '/atomic:operations'], $e->getSource());
            $this->assertStringContainsString('at most 2', $e->getMessage());
        }
    }

    public function testUpdateMayIdentifyItsTargetThroughData(): void
    {
        $operations = OperationsDocument::parse([
            'atomic:operations' => [
                ['op' => 'update', 'data' => ['type' => 'articles', 'id' => '5', 'attributes' => []]],
            ],
        ]);

        $this->assertNull($operations[0]->ref);
        $this->assertSame('articles', $operations[0]->type());
    }
}
