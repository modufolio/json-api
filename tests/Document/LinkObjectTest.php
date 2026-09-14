<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Document;

use InvalidArgumentException;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\LinkObject;
use PHPUnit\Framework\TestCase;

/**
 * JSON:API 1.1 link objects: href plus rel, describedby, title, type,
 * hreflang and meta, each emitted only when set.
 */
class LinkObjectTest extends TestCase
{
    public function testBareLinkIsJustAnHref(): void
    {
        $this->assertSame(['href' => 'https://example.com/a'], (new LinkObject('https://example.com/a'))->toArray());
    }

    public function testEveryMemberInSpecOrder(): void
    {
        $link = (new LinkObject('https://example.com/articles'))
            ->setRel('collection')
            ->setDescribedBy('https://example.com/schema')
            ->setTitle('Articles')
            ->setType('application/vnd.api+json')
            ->setHreflang(['en', 'nl'])
            ->setMeta(['count' => 3]);

        $this->assertSame([
            'href' => 'https://example.com/articles',
            'rel' => 'collection',
            'describedby' => 'https://example.com/schema',
            'title' => 'Articles',
            'type' => 'application/vnd.api+json',
            'hreflang' => ['en', 'nl'],
            'meta' => ['count' => 3],
        ], $link->toArray());
    }

    public function testDescribedByMayItselfBeALinkObject(): void
    {
        $link = (new LinkObject('https://example.com/a'))
            ->setDescribedBy((new LinkObject('https://example.com/schema'))->setType('application/schema+json'));

        $encoded = json_decode((string) json_encode($link), true);

        $this->assertSame(['href' => 'https://example.com/schema', 'type' => 'application/schema+json'], $encoded['describedby']);
    }

    public function testHreflangAcceptsASingleTag(): void
    {
        $this->assertSame('en', (new LinkObject('https://x'))->setHreflang('en')->toArray()['hreflang']);
    }

    public function testEmptyHreflangListIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LinkObject('https://x'))->setHreflang([]);
    }

    public function testEmptyHrefIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LinkObject('  ');
    }

    public function testTopLevelDescribedByLink(): void
    {
        $document = (new JsonApiDocument())
            ->setData([])
            ->setLinks([
                'self' => 'https://example.com/articles',
                'describedby' => new LinkObject('https://example.com/openapi.json'),
            ]);

        $encoded = json_decode((string) json_encode($document), true);

        $this->assertSame(['href' => 'https://example.com/openapi.json'], $encoded['links']['describedby']);
    }
}
