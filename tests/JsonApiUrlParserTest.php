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

    public function testFilterWithComplexOperators(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => [
                        'like' => '%hello%',
                        'neq' => 'world'
                    ],
                    'author' => [
                        'in' => 'john,jane,bob'  // comma-separated string should be converted to array
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'like' => '%hello%',
                'neq' => 'world'
            ],
            'author' => [
                'in' => ['john', 'jane', 'bob']  // Should be converted to array
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithInOperatorAsArray(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'author' => [
                        'in' => ['john', 'jane', 'bob']  // Already an array
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'author' => [
                'in' => ['john', 'jane', 'bob']  // Should remain as array
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithInOperatorNonStringNonArray(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'author' => [
                        'in' => 123  // Neither string nor array - should be wrapped in array
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'author' => [
                'in' => [123]  // Should be wrapped in array
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithInvalidOperators(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => [
                        'invalid_operator' => 'value',  // Invalid operator - should be ignored
                        'like' => '%hello%',            // Valid operator - should be kept
                        'another_bad' => 'test'         // Another invalid operator - should be ignored
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'like' => '%hello%'  // Only valid operator should remain
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithNonStringOperatorKeys(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => [
                        0 => 'numeric_key_ignored',  // Numeric key - should be ignored
                        'like' => '%hello%',         // Valid string key - should be kept
                        'eq' => 'test'              // Valid string key - should be kept
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'like' => '%hello%',
                'eq' => 'test'
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithAllValidOperators(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => [
                        'eq' => 'exact',
                        'neq' => 'not_this',
                        'not' => 'not_this_either',
                        'gt' => '5',
                        'gte' => '10',
                        'lt' => '20',
                        'lte' => '15',
                        'like' => '%pattern%',
                        'in' => 'a,b,c',
                        'null' => true,
                        'not_null' => true
                    ]
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'eq' => 'exact',
                'neq' => 'not_this',
                'not' => 'not_this_either',
                'gt' => '5',
                'gte' => '10',
                'lt' => '20',
                'lte' => '15',
                'like' => '%pattern%',
                'in' => ['a', 'b', 'c'],
                'null' => true,
                'not_null' => true
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithEmptyOperatorsArrayBecomesInOperator(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => [],  // Empty array is treated as indexed array -> becomes 'in' operator with empty values
                    'author' => 'john'  // Simple filter - should be kept
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'in' => []  // Empty array becomes empty 'in' operator
            ],
            'author' => 'john'
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }

    public function testFilterWithIndexedArrayBecomesInOperator(): void
    {
        $request = (new ServerRequest('GET', '/posts'))
            ->withQueryParams([
                'filter' => [
                    'title' => ['value1', 'value2', 'value3']  // Indexed array should become 'in' operator
                ]
            ]);

        $params = $this->parser->parse($request, 'App\\Entity\\Post');

        $expectedFilters = [
            'title' => [
                'in' => ['value1', 'value2', 'value3']  // Should be converted to 'in' operator
            ]
        ];

        $this->assertEquals($expectedFilters, $params->filter);
    }
}
