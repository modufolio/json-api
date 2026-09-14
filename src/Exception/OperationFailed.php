<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

use Modufolio\JsonApi\Atomic\Operation;
use Modufolio\JsonApi\Document\ErrorObject;

/**
 * An atomic operation failed with a JSON:API error.
 *
 * Wraps the failure the handler raised so it can be reported against the
 * operation it belongs to: the status and code are the original's, and the
 * `source.pointer` is the original's, re-rooted at
 * `/atomic:operations/{index}` — a `/data/attributes/title` from the
 * deserializer becomes `/atomic:operations/2/data/attributes/title`. A
 * failure with a `parameter` or `header` source, or none, gets the bare
 * operation pointer.
 */
class OperationFailed extends AbstractJsonApiException
{
    public function __construct(
        private readonly Operation $operation,
        private readonly JsonApiExceptionInterface $failure,
        string $pointerSuffix = '',
    ) {
        $source = $failure->getSource();
        $inner = $source['pointer'] ?? $pointerSuffix;

        // A handler that already pointed inside the operation is left alone;
        // a pointer into the operation's own document is re-rooted under it.
        $pointer = str_starts_with($inner, $operation->pointer())
            ? $inner
            : $operation->pointer($inner);

        parent::__construct(
            message: $failure->getMessage(),
            status: $failure->getStatus(),
            errorCode: $failure->getErrorCode(),
            source: ['pointer' => $pointer],
            previous: $failure,
        );
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    /**
     * The failure as the handler raised it.
     */
    public function getFailure(): JsonApiExceptionInterface
    {
        return $this->failure;
    }

    public function toErrorObject(): ErrorObject
    {
        // The original's error object keeps its own title and any meta; only
        // the source is re-rooted.
        return $this->failure->toErrorObject()->setSource($this->getSource());
    }

    protected function title(): string
    {
        return 'The atomic operation failed';
    }
}
