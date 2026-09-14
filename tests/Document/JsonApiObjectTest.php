<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use Modufolio\JsonApi\Atomic\AtomicExtension;
use Modufolio\JsonApi\Document\ErrorObject;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Http\MediaType;
use PHPUnit\Framework\TestCase;

/**
 * The `jsonapi` object's 1.1 members — ext, profile, meta — and the error
 * object's `source.header`.
 */
class JsonApiObjectTest extends TestCase
{
    public function testExtensionsAndProfilesAreListedBesideTheVersion(): void
    {
        $document = (new JsonApiDocument())
            ->setExtensions([AtomicExtension::URI])
            ->setProfiles(['https://example.com/p'])
            ->setJsonApiMeta(['implementation' => 'modufolio/json-api']);

        $this->assertSame([
            'version' => '1.1',
            'ext' => [AtomicExtension::URI],
            'profile' => ['https://example.com/p'],
            'meta' => ['implementation' => 'modufolio/json-api'],
        ], $document->getJsonApi());
    }

    public function testEmptyListsRemoveTheMember(): void
    {
        $document = (new JsonApiDocument())->setExtensions([AtomicExtension::URI])->setExtensions([]);

        $this->assertSame(['version' => '1.1'], $document->getJsonApi());
    }

    public function testMediaTypeFillsBothLists(): void
    {
        $document = (new JsonApiDocument())->setMediaType(
            MediaType::jsonApi([AtomicExtension::URI], ['https://example.com/p'])
        );

        $this->assertSame([AtomicExtension::URI], $document->getJsonApi()['ext']);
        $this->assertSame(['https://example.com/p'], $document->getJsonApi()['profile']);
    }

    public function testErrorSourceHelpersSetExactlyOneMember(): void
    {
        $error = (new ErrorObject())->setSourcePointer('/data/attributes/title')->setSourceHeader('Accept');

        $this->assertSame(['header' => 'Accept'], $error->jsonSerialize()['source']);

        $this->assertSame(
            ['parameter' => 'filter[author]'],
            (new ErrorObject())->setSourceParameter('filter[author]')->jsonSerialize()['source'],
        );
    }
}
