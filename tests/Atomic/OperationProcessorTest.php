<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Atomic;

use Doctrine\DBAL\Connection;
use Modufolio\JsonApi\Atomic\AtomicExtension;
use Modufolio\JsonApi\Atomic\Operation;
use Modufolio\JsonApi\Atomic\OperationHandler;
use Modufolio\JsonApi\Atomic\OperationProcessor;
use Modufolio\JsonApi\Atomic\OperationResult;
use Modufolio\JsonApi\Atomic\OperationsDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\Exception\OperationFailed;
use Modufolio\JsonApi\Exception\ResourceNotFound;
use Modufolio\JsonApi\Exception\ResourceTypeConflict;
use Modufolio\JsonApi\LidRegistry;
use Modufolio\JsonApi\Tests\Fixtures\TestDatabaseSetup;
use PHPUnit\Framework\TestCase;

/**
 * The processor's own guarantees, with a handler that records what it was
 * asked and a real SQLite transaction underneath.
 */
class OperationProcessorTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = TestDatabaseSetup::createEntityManager()->getConnection();
    }

    protected function tearDown(): void
    {
        TestDatabaseSetup::reset();
    }

    /**
     * @param callable(Operation, LidRegistry): OperationResult $fn
     */
    private function handler(callable $fn): OperationHandler
    {
        return new class ($fn) implements OperationHandler {
            /** @var callable(Operation, LidRegistry): OperationResult */
            private $fn;

            /**
             * @param callable(Operation, LidRegistry): OperationResult $fn
             */
            public function __construct(callable $fn)
            {
                $this->fn = $fn;
            }

            public function handle(Operation $operation, LidRegistry $lids): OperationResult
            {
                return ($this->fn)($operation, $lids);
            }
        };
    }

    public function testOperationsRunInOrderAndResultsLineUp(): void
    {
        $seen = [];
        $handler = $this->handler(function (Operation $op) use (&$seen): OperationResult {
            $seen[] = $op->index;

            return $op->op === Operation::REMOVE
                ? OperationResult::none()
                : OperationResult::of(new ResourceObject('articles', (string) (100 + $op->index)));
        });

        $document = (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
            'atomic:operations' => [
                ['op' => 'add', 'data' => ['type' => 'articles']],
                ['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '1']],
                ['op' => 'add', 'data' => ['type' => 'articles']],
            ],
        ]));

        $this->assertSame([0, 1, 2], $seen);
        $this->assertFalse($document->isEmpty());

        $encoded = json_decode((string) json_encode($document), true);
        $this->assertSame(['version' => '1.1', 'ext' => [AtomicExtension::URI]], $encoded['jsonapi']);
        $this->assertSame('100', $encoded['atomic:results'][0]['data']['id']);
        $this->assertSame([], $encoded['atomic:results'][1]);
        $this->assertSame('102', $encoded['atomic:results'][2]['data']['id']);
        $this->assertSame('application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"', $document->mediaType()->toString());
    }

    /**
     * An added resource's `lid` maps to the id in its result, and a later
     * operation's `ref.lid` resolves through it.
     */
    public function testLidFromAnAddIsVisibleToLaterOperations(): void
    {
        $resolved = null;
        $handler = $this->handler(function (Operation $op, LidRegistry $lids) use (&$resolved): OperationResult {
            if ($op->op === Operation::ADD) {
                return OperationResult::of(['type' => 'articles', 'id' => '77']);
            }
            $resolved = $op->ref?->resolveId($lids);

            return OperationResult::none();
        });

        (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
            'atomic:operations' => [
                ['op' => 'add', 'data' => ['type' => 'articles', 'lid' => 'new-article']],
                ['op' => 'remove', 'ref' => ['type' => 'articles', 'lid' => 'new-article']],
            ],
        ]));

        $this->assertSame('77', $resolved);
    }

    public function testEmptyResultsMakeAnEmptyDocument(): void
    {
        $handler = $this->handler(fn () => OperationResult::none());

        $document = (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
            'atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '1']]],
        ]));

        $this->assertTrue($document->isEmpty());
        $this->assertCount(1, $document->results());
    }

    /**
     * "a failure to perform any operation MUST invalidate any effects of
     * preceding operations."
     */
    public function testAFailureRollsBackEarlierOperations(): void
    {
        $handler = $this->handler(function (Operation $op): OperationResult {
            if ($op->index === 0) {
                $this->connection->insert('documents', ['title' => 'Written first', 'body' => null, 'status' => 'draft']);

                return OperationResult::none();
            }
            throw new ResourceNotFound('documents', '999');
        });

        try {
            (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
                'atomic:operations' => [
                    ['op' => 'add', 'data' => ['type' => 'documents']],
                    ['op' => 'remove', 'ref' => ['type' => 'documents', 'id' => '999']],
                ],
            ]));
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertSame('RESOURCE_NOT_FOUND', $e->getErrorCode());
            $this->assertSame(['pointer' => '/atomic:operations/1'], $e->getSource());
            $this->assertInstanceOf(ResourceNotFound::class, $e->getFailure());
            $this->assertSame(1, $e->getOperation()->index);
        }

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM documents'));
    }

    /**
     * A pointer the handler's failure already carries is re-rooted at the
     * operation, so `/data/type` becomes `/atomic:operations/0/data/type`.
     */
    public function testInnerPointersAreReRootedAtTheOperation(): void
    {
        $handler = $this->handler(fn () => throw new ResourceTypeConflict('articles', 'people', 'Wrong type.'));

        try {
            (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
                'atomic:operations' => [['op' => 'add', 'data' => ['type' => 'people']]],
            ]));
            $this->fail('Expected OperationFailed');
        } catch (OperationFailed $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame(['pointer' => '/atomic:operations/0/data/type'], $e->getSource());

            $error = $e->toErrorObject()->jsonSerialize();
            $this->assertSame('409', $error['status']);
            $this->assertSame('RESOURCE_TYPE_CONFLICT', $error['code']);
            $this->assertSame(['pointer' => '/atomic:operations/0/data/type'], $error['source']);
        }
    }

    public function testNonJsonApiFailuresPropagateUntouched(): void
    {
        $handler = $this->handler(fn () => throw new \RuntimeException('bug'));

        $this->expectException(\RuntimeException::class);

        (new OperationProcessor($handler, $this->connection))->process(OperationsDocument::parse([
            'atomic:operations' => [['op' => 'add', 'data' => ['type' => 'articles']]],
        ]));
    }
}
