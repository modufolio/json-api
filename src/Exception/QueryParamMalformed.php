<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

/**
 * A query parameter carried a value this endpoint cannot act on.
 *
 * The `source` names the parameter as the client wrote it — `filter[author]`,
 * `page[size]` — so the client can correct the request without guessing which
 * of several parameters was at fault.
 */
class QueryParamMalformed extends AbstractJsonApiException
{
    public function __construct(
        private readonly string $queryParam,
        string $detail,
    ) {
        parent::__construct(
            $detail,
            400,
            'QUERY_PARAM_MALFORMED',
            ['parameter' => $queryParam],
        );
    }

    public function getQueryParam(): string
    {
        return $this->queryParam;
    }

    protected function title(): string
    {
        return 'The query parameter is malformed';
    }
}
