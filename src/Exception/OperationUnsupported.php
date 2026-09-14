<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * A well-formed operation this server does not perform.
 *
 * An `href` target, a relationship the handler cannot write, a type with no
 * configured resource: the request is valid, the server declines it. The
 * base specification answers such requests with `403 Forbidden`.
 */
class OperationUnsupported extends AbstractJsonApiException
{
    public function __construct(string $detail, string $pointer = '')
    {
        parent::__construct(
            message: $detail,
            status: 403,
            errorCode: 'ATOMIC_OPERATION_UNSUPPORTED',
            source: $pointer !== '' ? ['pointer' => $pointer] : [],
        );
    }

    protected function title(): string
    {
        return 'The atomic operation is not supported';
    }
}
