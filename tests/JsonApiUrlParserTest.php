<?php

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\JsonApiQueryParams;
use Modufolio\JsonApi\JsonApiUrlParser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class JsonApiUrlParserTest extends TestCase
{
    private JsonApiUrlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new JsonApiUrlParser([
            'App\\Entity\\Post' => [
                'resource_key'    => 'posts',
                'fields'          => ['id', 'title', 'body', 'author'],
                'relationships'   => ['author', 'comments'],
            ],
        ]);
    }

    public function testParsesValidParamsAndIgnoresInvalidOnes(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'fields' => [
                    'posts' => 'id,title,invalid_field'
                ],
                'filter' => [
                    'title' => 'Hello',
                    'not_allowed' => 'value'
                ],
                'include' => 'author,invalid_rel',
                'sort'    => 'title,-invalid_sort',
                'page'    => [
                    'number' => '2',
                    'size'   => '20'
                ],
                'group'   => 'author,invalid_group',
                'having'  => [
                    'query' => 'title = foo', // valid format
                    'bindings' => ['foo']
                ]
            ])
            ->withAttribute('id', '123');

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $this->assertInstanceOf(JsonApiQueryParams::class, $params);

        // Only valid fields are kept
        $this->assertSame(['id', 'title'], $params->fields);

        // Only allowed filters kept
        $this->assertSame(['title' => 'Hello'], $params->filter);

        // Only allowed relationships kept
        $this->assertSame(['author'], $params->include);

        // Only valid sort kept (indexed array format with - prefix for DESC)
        $this->assertSame(['title'], $params->sort);

        // Pagination
        $this->assertSame(['number' => 2, 'size' => 20], $params->page);

        // Only allowed group fields kept
        $this->assertSame(['author'], $params->group);

        // Having clause remains
        $this->assertSame([
            'query' => 'title = foo',
            'bindings' => ['foo']
        ], $params->having);

        // ID from route
        $this->assertSame('123', $params->id);
    }

    public function testEmptyQueryParamsReturnsDefaults(): void
    {
        $request = new ServerRequest('GET', '/posts');
        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $this->assertSame([], $params->fields);
        $this->assertSame([], $params->filter);
        $this->assertSame([], $params->include);
        $this->assertSame([], $params->sort);
        $this->assertSame(['number' => 1, 'size' => 10], $params->page);
        $this->assertSame([], $params->group);
        $this->assertSame(['query' => '', 'bindings' => []], $params->having);
        $this->assertNull($params->id);
    }
}
