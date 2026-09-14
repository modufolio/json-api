<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * An `atomic:operations` document does not have the shape the extension
 * defines.
 *
 * The extension requires a 400 for this, with the error pointing at the
 * offending operation. Raised while parsing, before anything runs.
 */
class OperationMalformed extends AbstractJsonApiException
{
    public function __construct(string $detail, string $pointer)
    {
        parent::__construct(
            message: $detail,
            status: 400,
            errorCode: 'ATOMIC_OPERATION_MALFORMED',
            source: $pointer !== '' ? ['pointer' => $pointer] : [],
        );
    }

    protected function title(): string
    {
        return 'The atomic operation is malformed';
    }
}
