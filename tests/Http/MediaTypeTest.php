<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Http;

use InvalidArgumentException;
use Modufolio\JsonApi\Http\MediaType;
use PHPUnit\Framework\TestCase;

/**
 * The JSON:API 1.1 media type parameters, `ext` and `profile`, are quoted
 * space-separated URI lists. The parser has to get quoting right and keep
 * them apart from every other parameter.
 */
class MediaTypeTest extends TestCase
{
    public function testParsesTheBareJsonApiType(): void
    {
        $type = MediaType::parse('application/vnd.api+json');

        $this->assertTrue($type->isJsonApi());
        $this->assertSame([], $type->extensions);
        $this->assertSame([], $type->profiles);
        $this->assertSame([], $type->parameters);
        $this->assertTrue($type->hasOnlyJsonApiParameters());
    }

    public function testParsesQuotedExtAndProfileLists(): void
    {
        $type = MediaType::parse(
            'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic https://example.com/ext"; profile="https://example.com/p1 https://example.com/p2"'
        );

        $this->assertSame(['https://jsonapi.org/ext/atomic', 'https://example.com/ext'], $type->extensions);
        $this->assertSame(['https://example.com/p1', 'https://example.com/p2'], $type->profiles);
        $this->assertTrue($type->hasOnlyJsonApiParameters());
    }

    public function testSemicolonsInsideQuotesDoNotSplitParameters(): void
    {
        $type = MediaType::parse('application/vnd.api+json; profile="https://example.com/p;v=2"');

        $this->assertSame(['https://example.com/p;v=2'], $type->profiles);
    }

    public function testCharsetIsAForeignParameter(): void
    {
        $type = MediaType::parse('application/vnd.api+json; charset=utf-8');

        $this->assertSame(['charset' => 'utf-8'], $type->parameters);
        $this->assertFalse($type->hasOnlyJsonApiParameters());
    }

    public function testTypeAndParameterNamesAreCaseInsensitive(): void
    {
        $type = MediaType::parse('Application/VND.API+JSON; EXT="https://a"');

        $this->assertTrue($type->isJsonApi());
        $this->assertSame(['https://a'], $type->extensions);
    }

    public function testRejectsSomethingThatIsNotAMediaType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaType::parse('not a media type');
    }

    public function testRendersExtAndProfileQuoted(): void
    {
        $type = MediaType::jsonApi(['https://jsonapi.org/ext/atomic'], ['https://example.com/p']);

        $this->assertSame(
            'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"; profile="https://example.com/p"',
            (string) $type,
        );
    }

    public function testRenderingDropsTheQualityWeight(): void
    {
        $this->assertSame('application/vnd.api+json', MediaType::parse('application/vnd.api+json;q=0.8')->toString());
    }

    public function testAcceptListIsOrderedByQualityThenSpecificityThenPosition(): void
    {
        $list = MediaType::parseList(
            '*/*;q=0.1, application/vnd.api+json;q=0.5, text/html, application/vnd.api+json; ext="https://a", application/*'
        );

        $this->assertSame(
            ['text/html', 'application/vnd.api+json; ext="https://a"', 'application/*', 'application/vnd.api+json', '*/*'],
            array_map(static fn (MediaType $t) => $t->toString(), $list),
        );
    }

    public function testAcceptListSkipsGarbageEntries(): void
    {
        $list = MediaType::parseList('garbage, , application/vnd.api+json');

        $this->assertCount(1, $list);
        $this->assertTrue($list[0]->isJsonApi());
    }

    public function testWildcardsMatchJsonApiWithoutBeingIt(): void
    {
        foreach (['*/*', 'application/*'] as $wildcard) {
            $type = MediaType::parse($wildcard);
            $this->assertTrue($type->matchesJsonApi(), $wildcard);
            $this->assertFalse($type->isJsonApi(), $wildcard);
        }

        $this->assertFalse(MediaType::parse('text/*')->matchesJsonApi());
    }
}
