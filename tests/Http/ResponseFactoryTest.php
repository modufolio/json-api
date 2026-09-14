<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Http;

use Modufolio\JsonApi\Atomic\AtomicExtension;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Http\MediaType;
use Modufolio\JsonApi\Http\ResponseFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class ResponseFactoryTest extends TestCase
{
    private function factory(): ResponseFactory
    {
        $psr17 = new Psr17Factory();

        return new ResponseFactory($psr17, $psr17);
    }

    public function testJsonApiResponseCarriesTheJsonApiMediaType(): void
    {
        $document = (new JsonApiDocument())->setData(null);

        $response = $this->factory()->jsonApi($document);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.api+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('Accept', $response->getHeaderLine('Vary'));
        $this->assertSame(['version' => '1.1'], json_decode((string) $response->getBody(), true)['jsonapi']);
    }

    /**
     * "Clients and servers MUST specify the ext media type parameter in the
     * Content-Type header when they have applied one or more extensions."
     */
    public function testNegotiatedExtensionsAndProfilesReachTheHeader(): void
    {
        $mediaType = MediaType::jsonApi([AtomicExtension::URI], ['https://example.com/p']);

        $response = $this->factory()->jsonApi(['meta' => []], 200, $mediaType);

        $this->assertSame(
            'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"; profile="https://example.com/p"',
            $response->getHeaderLine('Content-Type'),
        );
    }

    public function testNonJsonApiMediaTypeIsEmittedBare(): void
    {
        $response = $this->factory()->jsonApi(['meta' => []], 200, MediaType::parse('application/json; charset=utf-8'));

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testExplicitContentTypeHeaderWins(): void
    {
        $response = $this->factory()->jsonApi(['meta' => []], 200, null, ['Content-Type' => 'text/plain']);

        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }
}
