<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Exception;

use InvalidArgumentException;
use Modufolio\JsonApi\Exception\FieldUnrecognized;
use Modufolio\JsonApi\Exception\InclusionUnrecognized;
use Modufolio\JsonApi\Exception\JsonApiExceptionInterface;
use Modufolio\JsonApi\Exception\MediaTypeUnacceptable;
use Modufolio\JsonApi\Exception\MediaTypeUnsupported;
use Modufolio\JsonApi\Exception\QueryParamMalformed;
use Modufolio\JsonApi\Exception\ResourceNotFound;
use PHPUnit\Framework\TestCase;

/**
 * The typed failures carry a status, a stable code and a `source`, so a
 * controller can render a spec-shaped error document without reading messages.
 */
class JsonApiExceptionTest extends TestCase
{
    /**
     * Every throw site in the library predates these types and threw
     * InvalidArgumentException. Catching that must keep working.
     */
    public function testEachTypeIsStillAnInvalidArgumentException(): void
    {
        foreach ($this->allExceptions() as $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
            $this->assertInstanceOf(JsonApiExceptionInterface::class, $exception);
        }
    }

    public function testStatusesMatchTheFailureKind(): void
    {
        $this->assertSame(415, (new MediaTypeUnsupported('text/xml'))->getStatus());
        $this->assertSame(406, (new MediaTypeUnacceptable('text/xml'))->getStatus());
        $this->assertSame(404, (new ResourceNotFound('contact', '9'))->getStatus());
        $this->assertSame(400, (new QueryParamMalformed('page[size]', 'Not a number.'))->getStatus());
        $this->assertSame(400, (new InclusionUnrecognized('author', 'Unknown include.'))->getStatus());
        $this->assertSame(400, (new FieldUnrecognized(['nope']))->getStatus());
    }

    /**
     * A client branches on the code, so it must not drift with the message.
     */
    public function testCodesAreStable(): void
    {
        $this->assertSame('MEDIA_TYPE_UNSUPPORTED', (new MediaTypeUnsupported('text/xml'))->getErrorCode());
        $this->assertSame('RESOURCE_NOT_FOUND', (new ResourceNotFound('contact', '9'))->getErrorCode());
        $this->assertSame('QUERY_PARAM_MALFORMED', (new QueryParamMalformed('sort', 'Bad.'))->getErrorCode());
        $this->assertSame('INCLUSION_UNRECOGNIZED', (new InclusionUnrecognized('a.b', 'Bad.'))->getErrorCode());
        $this->assertSame('FIELD_UNRECOGNIZED', (new FieldUnrecognized(['nope']))->getErrorCode());
    }

    public function testSourceNamesTheOffendingParameter(): void
    {
        $this->assertSame(['parameter' => 'page[size]'], (new QueryParamMalformed('page[size]', 'Bad.'))->getSource());
        $this->assertSame(['parameter' => 'include'], (new InclusionUnrecognized('a.b', 'Bad.'))->getSource());
        $this->assertSame(['parameter' => 'content-type'], (new MediaTypeUnsupported('text/xml'))->getSource());
        $this->assertSame(['parameter' => 'accept'], (new MediaTypeUnacceptable('text/xml'))->getSource());
    }

    /**
     * A failure that cannot be attributed to one part of the request carries no
     * `source` at all, rather than an empty one the client has to ignore.
     */
    public function testAnUnattributableFailureHasNoSourceMember(): void
    {
        $notFound = new ResourceNotFound('contact', '9');

        $this->assertSame([], $notFound->getSource());
        $this->assertArrayNotHasKey('source', $notFound->toErrorObject()->jsonSerialize());
    }

    public function testRendersAsAnErrorObject(): void
    {
        $error = (new QueryParamMalformed('page[size]', 'Size must be a number.'))
            ->toErrorObject()
            ->jsonSerialize();

        $this->assertSame('400', $error['status']);
        $this->assertSame('QUERY_PARAM_MALFORMED', $error['code']);
        $this->assertSame('The query parameter is malformed', $error['title']);
        $this->assertSame('Size must be a number.', $error['detail']);
        $this->assertSame(['parameter' => 'page[size]'], $error['source']);
    }

    public function testTheOffendingInputIsAvailableForHandling(): void
    {
        $this->assertSame(['a', 'b'], (new FieldUnrecognized(['a', 'b']))->getFields());
        $this->assertSame('comments.author', (new InclusionUnrecognized('comments.author', 'Bad.'))->getIncludePath());
        $this->assertSame('text/xml', (new MediaTypeUnsupported('text/xml'))->getMediaType());
        $this->assertSame('9', (new ResourceNotFound('contact', '9'))->getId());
    }

    /**
     * @return list<JsonApiExceptionInterface>
     */
    private function allExceptions(): array
    {
        return [
            new MediaTypeUnsupported('text/xml'),
            new MediaTypeUnacceptable('text/xml'),
            new ResourceNotFound('contact', '9'),
            new QueryParamMalformed('sort', 'Bad.'),
            new InclusionUnrecognized('a.b', 'Bad.'),
            new FieldUnrecognized(['nope']),
        ];
    }
}
