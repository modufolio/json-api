<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

use Modufolio\JsonApi\Document\ErrorObject;
use Throwable;

/**
 * A failure that already knows how it should reach the client.
 *
 * A bare InvalidArgumentException tells a controller nothing: it cannot know
 * whether the request was malformed (400), the media type unsupported (415) or
 * the record absent (404), so every such failure tends to surface as a 500 —
 * or as a hand-rolled guess at the call site. These exceptions carry the status
 * they warrant, a stable machine-readable code, and the `source` member telling
 * the client which parameter or pointer caused it, so a controller can render a
 * spec-shaped error document without inspecting the message text.
 */
interface JsonApiExceptionInterface extends Throwable
{
    /**
     * The HTTP status this failure warrants.
     */
    public function getStatus(): int;

    /**
     * Stable, machine-readable identifier for this class of failure.
     *
     * Clients branch on this rather than on the human-readable detail, which
     * is free to change without being a breaking change.
     */
    public function getErrorCode(): string;

    /**
     * The JSON:API `source` member, or an empty array when the failure cannot
     * be attributed to one part of the request.
     *
     * @return array<string, string>
     */
    public function getSource(): array;

    /**
     * Render as an error object ready for a document's `errors` member.
     */
    public function toErrorObject(): ErrorObject;
}
