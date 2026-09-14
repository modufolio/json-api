<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Atomic;

use Modufolio\JsonApi\LidRegistry;

/**
 * Executes one atomic operation against the application's data.
 *
 * {@see OperationProcessor} owns the sequence, the transaction and the
 * pointer-prefixing of errors; the handler owns what an `add`, `update` or
 * `remove` means for a given type. Throw a
 * {@see \Modufolio\JsonApi\Exception\JsonApiExceptionInterface} to fail the
 * request with a proper status — the processor rolls everything back and
 * points the error at the operation.
 *
 * When an `add` creates a resource the client named with a `lid`, the
 * processor registers the id from the returned result; a handler that
 * creates several resources in one operation registers the rest itself.
 *
 * {@see QueryBuilderOperationHandler} is the implementation over
 * {@see \Modufolio\JsonApi\JsonApiQueryBuilder}; write your own to run
 * operations through Doctrine entities, a service layer or anything else.
 */
interface OperationHandler
{
    public function handle(Operation $operation, LidRegistry $lids): OperationResult;
}
