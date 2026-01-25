<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Simple PSR-7 Response Factory for JSON API
 */
readonly class ResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface   $streamFactory
    ) {
    }

    /**
     * Create a JSON response
     */
    public function json(array|string $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $jsonOptions = JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

        $body = is_array($data)
            ? json_encode($data, $jsonOptions)
            : $data;

        $response = $this->responseFactory->createResponse($status);
        $stream = $this->streamFactory->createStream($body);
        $response = $response->withBody($stream);

        // Set default Content-Type if not provided
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Create an empty response
     */
    public function empty(int $status = 204): ResponseInterface
    {
        return $this->responseFactory->createResponse($status);
    }
}
