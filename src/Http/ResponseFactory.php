<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Http;

use Modufolio\JsonApi\Document\JsonApiDocument;
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
     *
     * @param array<string, mixed>|string                    $data
     * @param array<string, string|array<int, string>>       $headers
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
     * Create a JSON:API response.
     *
     * The `Content-Type` is the JSON:API media type, modified by the `ext`
     * and `profile` parameters of `$mediaType` when one is given — which is
     * how the specification has a server announce the extensions and
     * profiles it applied. Pass the value {@see MediaTypeNegotiator} returned
     * for the request, and set the same one on the document with
     * {@see JsonApiDocument::setMediaType()}, so header and `jsonapi` object
     * agree.
     *
     * A non-JSON:API `$mediaType` (an `application/json` the endpoint also
     * accepts, say) is emitted as given, without parameters. `Vary: Accept`
     * is added unless the headers already carry a `Vary`.
     *
     * @param JsonApiDocument|array<string, mixed>|string $document
     * @param array<string, string|array<int, string>>    $headers Extra headers; a `Content-Type` here wins
     */
    public function jsonApi(
        JsonApiDocument|array|string $document,
        int $status = 200,
        ?MediaType $mediaType = null,
        array $headers = [],
    ): ResponseInterface {
        if ($document instanceof JsonApiDocument) {
            $document = $document->toArray();
        }

        if (!isset($headers['Content-Type'])) {
            $mediaType ??= MediaType::jsonApi();
            $headers['Content-Type'] = $mediaType->isJsonApi()
                ? MediaType::jsonApi($mediaType->extensions, $mediaType->profiles)->toString()
                : $mediaType->type;
        }

        // The representation depends on the Accept header (which extensions
        // and profiles were applied), so a cache must key on it.
        $headers['Vary'] ??= 'Accept';

        return $this->json($document, $status, $headers);
    }

    /**
     * Create an empty response
     */
    public function empty(int $status = 204): ResponseInterface
    {
        return $this->responseFactory->createResponse($status);
    }
}
