<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Doctrine\DBAL\Connection;
use Modufolio\JsonApi\Exception\JsonApiExceptionInterface;
use Modufolio\JsonApi\Exception\OperationFailed;
use Modufolio\JsonApi\LidRegistry;
use Throwable;

/**
 * Runs an atomic operations request: in order, in one transaction, all or
 * nothing.
 *
 * The extension's three guarantees are made here. Operations execute in the
 * order given. They share a database transaction, so the failure of any one
 * undoes the effects of those before it. And a `lid` an `add` assigns is
 * visible to every later operation through the {@see LidRegistry} the
 * handler receives — the processor registers it itself from the result's
 * id, so a handler that returns the created resource has nothing extra to do.
 *
 * Local identifiers are validated up front ({@see LidValidator}), so a
 * duplicate or dangling `lid` is refused before the transaction opens.
 *
 * A JSON:API failure raised by the handler is re-thrown as
 * {@see OperationFailed}, which keeps the status and code and prefixes the
 * `source.pointer` with the operation's position, as the extension asks
 * error objects to do. Any other throwable propagates untouched after the
 * rollback: it is a bug, not a client error, and should surface as one.
 */
final class OperationProcessor
{
    private readonly LidValidator $lidValidator;

    public function __construct(
        private readonly OperationHandler $handler,
        private readonly Connection $connection,
    ) {
        $this->lidValidator = new LidValidator();
    }

    /**
     * @param list<Operation> $operations Typically from {@see OperationsDocument::parse()}
     *
     * @throws \Modufolio\JsonApi\Exception\LidUnresolved
     * @throws \Modufolio\JsonApi\Exception\LidConflict   before anything runs, from the pre-flight `lid` check
     * @throws OperationFailed
     * @throws Throwable Whatever the handler threw that was not a JSON:API failure
     */
    public function process(array $operations, ?LidRegistry $lids = null): ResultsDocument
    {
        // Every lid reference is checked against the declaration order
        // before a transaction is opened: a request that cannot succeed
        // should cost nothing and fail with a precise pointer.
        $this->lidValidator->validate($operations);

        $lids ??= new LidRegistry();
        $document = new ResultsDocument();

        $this->connection->transactional(function () use ($operations, $lids, $document): void {
            foreach ($operations as $operation) {
                try {
                    $result = $this->handler->handle($operation, $lids);
                } catch (OperationFailed $e) {
                    throw $e;
                } catch (JsonApiExceptionInterface $e) {
                    throw new OperationFailed(operation: $operation, failure: $e);
                }

                $this->registerLid($operation, $result, $lids);
                $document->add($result);
            }
        });

        return $document;
    }

    private function registerLid(Operation $operation, OperationResult $result, LidRegistry $lids): void
    {
        if ($operation->op !== Operation::ADD || $operation->targetsRelationship()) {
            return;
        }

        $lid = $operation->dataLid();
        $type = $operation->type();
        $id = $result->resourceId();

        if ($lid === null || $type === null || $id === null) {
            return;
        }

        try {
            $lids->register($type, $lid, $id);
        } catch (JsonApiExceptionInterface $e) {
            throw new OperationFailed(operation: $operation, failure: $e, pointerSuffix: '/data/lid');
        }
    }
}
