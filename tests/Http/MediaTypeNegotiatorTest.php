<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Http;

use Modufolio\JsonApi\Atomic\AtomicExtension;
use Modufolio\JsonApi\Exception\MediaTypeUnacceptable;
use Modufolio\JsonApi\Exception\MediaTypeUnsupported;
use Modufolio\JsonApi\Http\MediaTypeNegotiator;
use PHPUnit\Framework\TestCase;

/**
 * JSON:API 1.1 content negotiation: 415 for a body the server cannot read,
 * 406 for a response the client cannot take, and the ext/profile lists both
 * sides agreed on otherwise.
 */
class MediaTypeNegotiatorTest extends TestCase
{
    private const PROFILE = 'https://example.com/profiles/timestamps';

    private function negotiator(): MediaTypeNegotiator
    {
        return new MediaTypeNegotiator(
            supportedExtensions: [AtomicExtension::URI],
            supportedProfiles: [self::PROFILE],
            otherContentTypes: ['application/json'],
        );
    }

    // ── Content-Type ────────────────────────────────────────────────────────

    public function testPlainJsonApiBodyIsSupported(): void
    {
        $type = $this->negotiator()->negotiateContentType('application/vnd.api+json');

        $this->assertTrue($type->isJsonApi());
        $this->assertSame([], $type->extensions);
    }

    public function testSupportedExtensionIsAccepted(): void
    {
        $type = $this->negotiator()->negotiateContentType(
            'application/vnd.api+json; ext="' . AtomicExtension::URI . '"'
        );

        $this->assertSame([AtomicExtension::URI], $type->extensions);
    }

    /**
     * "the server MUST respond with a 415 Unsupported Media Type status code"
     * for an ext naming an extension it does not support.
     */
    public function testUnsupportedExtensionIs415(): void
    {
        try {
            $this->negotiator()->negotiateContentType('application/vnd.api+json; ext="https://example.com/nope"');
            $this->fail('Expected MediaTypeUnsupported');
        } catch (MediaTypeUnsupported $e) {
            $this->assertSame(415, $e->getStatus());
            $this->assertSame(['header' => 'Content-Type'], $e->getSource());
            $this->assertStringContainsString('https://example.com/nope', $e->getMessage());
        }
    }

    /**
     * "with any media type parameters other than ext or profile" → 415.
     * charset included: the JSON:API media type defines its own encoding.
     */
    public function testForeignParameterIs415(): void
    {
        $this->expectException(MediaTypeUnsupported::class);

        $this->negotiator()->negotiateContentType('application/vnd.api+json; charset=utf-8');
    }

    public function testUnsupportedProfileIsIgnoredNotRejected(): void
    {
        $type = $this->negotiator()->negotiateContentType(
            'application/vnd.api+json; profile="https://example.com/unknown ' . self::PROFILE . '"'
        );

        $this->assertSame([self::PROFILE], $type->profiles);
    }

    public function testOtherContentTypesPassWithAnyParameters(): void
    {
        $type = $this->negotiator()->negotiateContentType('application/json; charset=utf-8');

        $this->assertSame('application/json', $type->type);
    }

    public function testUnlistedContentTypeIs415(): void
    {
        $this->expectException(MediaTypeUnsupported::class);

        $this->negotiator()->negotiateContentType('text/xml');
    }

    public function testMissingContentTypeIs415(): void
    {
        $this->expectException(MediaTypeUnsupported::class);

        $this->negotiator()->negotiateContentType('');
    }

    // ── Accept ──────────────────────────────────────────────────────────────

    public function testMissingAcceptMeansPlainJsonApi(): void
    {
        $this->assertSame('application/vnd.api+json', $this->negotiator()->negotiateAccept('')->toString());
    }

    public function testWildcardMeansPlainJsonApi(): void
    {
        $this->assertSame('application/vnd.api+json', $this->negotiator()->negotiateAccept('*/*')->toString());
    }

    public function testPreferredSupportedVariantWins(): void
    {
        $type = $this->negotiator()->negotiateAccept(
            'application/vnd.api+json; ext="' . AtomicExtension::URI . '", application/vnd.api+json;q=0.5'
        );

        $this->assertSame([AtomicExtension::URI], $type->extensions);
    }

    /**
     * "servers MUST ignore instances of that media type which are modified
     * by a media type parameter other than ext or profile."
     */
    public function testInstancesWithForeignParametersAreIgnored(): void
    {
        $type = $this->negotiator()->negotiateAccept(
            'application/vnd.api+json; charset=utf-8, application/vnd.api+json;q=0.5'
        );

        $this->assertSame('application/vnd.api+json', $type->toString());
    }

    /**
     * "If all instances of that media type are modified with a media type
     * parameter other than ext or profile, servers MUST respond with a 406."
     */
    public function testOnlyForeignParameterInstancesIs406(): void
    {
        try {
            $this->negotiator()->negotiateAccept('application/vnd.api+json; charset=utf-8');
            $this->fail('Expected MediaTypeUnacceptable');
        } catch (MediaTypeUnacceptable $e) {
            $this->assertSame(406, $e->getStatus());
            $this->assertSame(['header' => 'Accept'], $e->getSource());
        }
    }

    public function testOnlyUnsupportedExtensionInstancesIs406(): void
    {
        $this->expectException(MediaTypeUnacceptable::class);

        $this->negotiator()->negotiateAccept('application/vnd.api+json; ext="https://example.com/nope"');
    }

    public function testUnsupportedExtensionFallsThroughToASupportedInstance(): void
    {
        $type = $this->negotiator()->negotiateAccept(
            'application/vnd.api+json; ext="https://example.com/nope", application/vnd.api+json;q=0.9'
        );

        $this->assertSame([], $type->extensions);
    }

    public function testUnsupportedProfileInAcceptIsIgnored(): void
    {
        $type = $this->negotiator()->negotiateAccept(
            'application/vnd.api+json; profile="https://example.com/unknown ' . self::PROFILE . '"'
        );

        $this->assertSame([self::PROFILE], $type->profiles);
    }

    public function testNoJsonApiAtAllIs406(): void
    {
        $this->expectException(MediaTypeUnacceptable::class);

        $this->negotiator()->negotiateAccept('text/html, application/xml');
    }

    public function testZeroQualityJsonApiIsNotAcceptable(): void
    {
        $this->expectException(MediaTypeUnacceptable::class);

        $this->negotiator()->negotiateAccept('application/vnd.api+json;q=0');
    }
}
