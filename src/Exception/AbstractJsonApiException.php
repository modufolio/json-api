<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Exception;

use InvalidArgumentException;
use Modufolio\JsonApi\Document\ErrorObject;
use Throwable;

/**
 * Base for the typed request failures.
 *
 * Extends InvalidArgumentException so that code catching that — which is what
 * every throw site in this library used before these types existed — keeps
 * working unchanged. Catch {@see JsonApiExceptionInterface} instead when you
 * want the status and source rather than just the message.
 */
abstract class AbstractJsonApiException extends InvalidArgumentException implements JsonApiExceptionInterface
{
    /**
     * @param array<string, string> $source
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly array $source = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, string>
     */
    public function getSource(): array
    {
        return $this->source;
    }

    public function toErrorObject(): ErrorObject
    {
        $error = (new ErrorObject())
            ->setStatus($this->status)
            ->setCode($this->errorCode)
            ->setTitle($this->title())
            ->setDetail($this->getMessage());

        if ($this->source !== []) {
            $error->setSource($this->source);
        }

        return $error;
    }

    /**
     * Short, human-readable summary that does not vary between occurrences.
     */
    abstract protected function title(): string;
}
