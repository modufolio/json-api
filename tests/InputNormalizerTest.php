<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests;

use Modufolio\JsonApi\InputNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InputNormalizerTest extends TestCase
{
    private InputNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new InputNormalizer();
    }

    public function testNormalizeJsonApiFormat(): void
    {
        $payload = [
            'data' => [
                'type' => 'product',
                'attributes' => [
                    'name' => 'iPhone 15',
                    'price' => 999.99,
                    'description' => 'Latest iPhone model'
                ],
                'relationships' => [
                    'brand' => [
                        'data' => ['type' => 'brand', 'id' => '1']
                    ],
                    'categories' => [
                        'data' => [
                            ['type' => 'category', 'id' => '1'],
                            ['type' => 'category', 'id' => '2']
                        ]
                    ]
                ]
            ]
        ];

        $result = $this->normalizer->normalize($payload, 'application/vnd.api+json', 'product');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);
        
        // Check attributes
        $this->assertEquals('iPhone 15', $result['attributes']['name']);
        $this->assertEquals(999.99, $result['attributes']['price']);
        $this->assertEquals('Latest iPhone model', $result['attributes']['description']);
        
        // Check relationships
        $this->assertEquals(1, $result['relationships']['brand']);
        $this->assertEquals([1, 2], $result['relationships']['categories']);
    }

    public function testNormalizePlainJsonFormat(): void
    {
        $payload = [
            'name' => 'MacBook Pro',
            'price' => 2499.99,
            'brand_id' => 2,
            'category_ids' => [3, 4, 5],
            'description' => 'High-performance laptop',
            'in_stock' => true
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'product');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);

        // Check attributes
        $this->assertEquals('MacBook Pro', $result['attributes']['name']);
        $this->assertEquals(2499.99, $result['attributes']['price']);
        $this->assertEquals('High-performance laptop', $result['attributes']['description']);
        $this->assertTrue($result['attributes']['in_stock']);

        // Check relationships (note: category_ids becomes 'category', not 'categories')
        $this->assertEquals(2, $result['relationships']['brand']);
        $this->assertEquals([3, 4, 5], $result['relationships']['category']);
    }

    public function testNormalizeFormUrlEncodedFormat(): void
    {
        $payload = [
            'name' => 'iPad Air',
            'price' => 599.99,
            'brand_id' => 1
        ];

        $result = $this->normalizer->normalize($payload, 'application/x-www-form-urlencoded', 'product');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);

        $this->assertEquals('iPad Air', $result['attributes']['name']);
        $this->assertEquals(599.99, $result['attributes']['price']);
        $this->assertEquals(1, $result['relationships']['brand']);
    }

    public function testNormalizeUnsupportedContentTypeFallsBackToJson(): void
    {
        $payload = [
            'name' => 'Product Name',
            'brand_id' => 3
        ];

        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('relationships', $result);
        $this->assertEquals('Product Name', $result['attributes']['name']);
        $this->assertEquals(3, $result['relationships']['brand']);
    }

    public function testNormalizeJsonWithNullRelationship(): void
    {
        $payload = [
            'name' => 'Orphan Product',
            'price' => 100.00,
            'brand_id' => null,
            'category_ids' => []
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'product');

        $this->assertNull($result['relationships']['brand']);
        $this->assertEquals([], $result['relationships']['category']);  // category, not categories
    }

    public function testNormalizeJsonWithNonArrayCategoryIds(): void
    {
        $payload = [
            'name' => 'Product',
            'category_ids' => 'invalid'  // Not an array
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'product');

        // Should be treated as regular attribute since it's not an array
        $this->assertEquals('invalid', $result['attributes']['category_ids']);
        $this->assertArrayNotHasKey('categories', $result['relationships']);
    }

    public function testNormalizeJsonWithComplexRelationships(): void
    {
        $payload = [
            'title' => 'Article Title',
            'author_id' => 10,
            'tag_ids' => [1, 2, 3],
            'reviewer_id' => 5,
            'editor_ids' => [7, 8],
            'content' => 'Article content here'
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'article');

        // Attributes
        $this->assertEquals('Article Title', $result['attributes']['title']);
        $this->assertEquals('Article content here', $result['attributes']['content']);

        // To-one relationships
        $this->assertEquals(10, $result['relationships']['author']);
        $this->assertEquals(5, $result['relationships']['reviewer']);

        // To-many relationships (note: _ids suffix is removed, so tag_ids becomes 'tag', editor_ids becomes 'editor')
        $this->assertEquals([1, 2, 3], $result['relationships']['tag']);
        $this->assertEquals([7, 8], $result['relationships']['editor']);
    }

    public function testMergeData(): void
    {
        $normalizedData = [
            'attributes' => [
                'name' => 'Product Name',
                'price' => 199.99
            ],
            'relationships' => [
                'brand' => 1,
                'categories' => [2, 3]
            ]
        ];

        $result = $this->normalizer->mergeData($normalizedData);

        $expected = [
            'name' => 'Product Name',
            'price' => 199.99,
            'brand' => 1,
            'categories' => [2, 3]
        ];

        $this->assertEquals($expected, $result);
    }

    public function testMergeDataWithEmptyAttributes(): void
    {
        $normalizedData = [
            'relationships' => [
                'user' => 5
            ]
        ];

        $result = $this->normalizer->mergeData($normalizedData);
        $this->assertEquals(['user' => 5], $result);
    }

    public function testMergeDataWithEmptyRelationships(): void
    {
        $normalizedData = [
            'attributes' => [
                'name' => 'Test'
            ]
        ];

        $result = $this->normalizer->mergeData($normalizedData);
        $this->assertEquals(['name' => 'Test'], $result);
    }

    public function testMergeDataWithEmptyArray(): void
    {
        $result = $this->normalizer->mergeData([]);
        $this->assertEquals([], $result);
    }

    public function testIsJsonApiFormat(): void
    {
        // Valid JSON:API format
        $jsonApiPayload = [
            'data' => [
                'type' => 'product',
                'attributes' => ['name' => 'Product']
            ]
        ];
        $this->assertTrue($this->normalizer->isJsonApiFormat($jsonApiPayload));

        // Plain JSON format
        $plainPayload = [
            'name' => 'Product',
            'price' => 100
        ];
        $this->assertFalse($this->normalizer->isJsonApiFormat($plainPayload));

        // Payload with data but not array
        $invalidPayload = [
            'data' => 'not an array'
        ];
        $this->assertFalse($this->normalizer->isJsonApiFormat($invalidPayload));

        // Empty payload
        $this->assertFalse($this->normalizer->isJsonApiFormat([]));
    }

    public function testDetectContentType(): void
    {
        // Test the underlying issue - perhaps the negotiation isn't working as expected
        
        // Try a simple direct test first
        $result = $this->normalizer->detectContentType('application/vnd.api+json');
        $this->assertEquals('jsonapi', $result);

        // JSON content type  
        $this->assertEquals('json', $this->normalizer->detectContentType('application/json'));
        $this->assertEquals('json', $this->normalizer->detectContentType('application/json; charset=utf-8'));

        // Form content type
        $this->assertEquals('form', $this->normalizer->detectContentType('application/x-www-form-urlencoded'));

        // Unsupported content type (defaults to json)
        $this->assertEquals('json', $this->normalizer->detectContentType('text/plain'));
        $this->assertEquals('json', $this->normalizer->detectContentType('application/xml'));
        // Note: empty string not tested as it throws exception in Negotiator library
    }

    public function testIsSupported(): void
    {
        // Test supported content types (exact matches only due to Negotiator behavior)
        $this->assertTrue($this->normalizer->isSupported('application/vnd.api+json'));
        $this->assertTrue($this->normalizer->isSupported('application/json'));
        $this->assertTrue($this->normalizer->isSupported('application/x-www-form-urlencoded'));

        // Content types with parameters are not supported by isSupported() 
        // due to Negotiator's getBest() behavior, even though normalize() handles them
        // via fallback to normalizeJson()
        $this->assertFalse($this->normalizer->isSupported('application/json; charset=utf-8'));
        $this->assertFalse($this->normalizer->isSupported('application/vnd.api+json; charset=utf-8'));

        // Unsupported content types
        $this->assertFalse($this->normalizer->isSupported('text/plain'));
        $this->assertFalse($this->normalizer->isSupported('application/xml'));
        $this->assertFalse($this->normalizer->isSupported('multipart/form-data'));
    }

    public function testIsSupportedVsNormalizeConsistency(): void
    {
        // This test documents the inconsistency between isSupported() and normalize()
        // isSupported() returns false for content types with parameters
        $this->assertFalse($this->normalizer->isSupported('application/json; charset=utf-8'));
        
        // But normalize() can handle them via fallback to normalizeJson()
        $payload = ['name' => 'test'];
        $result = $this->normalizer->normalize($payload, 'application/json; charset=utf-8', 'product');
        
        // Normalize should work even though isSupported returns false
        $this->assertIsArray($result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertEquals(['name' => 'test'], $result['attributes']);
    }

    public function testNormalizeWithStringIdsInCategoryIds(): void
    {
        $payload = [
            'name' => 'Product',
            'category_ids' => ['1', '2', '3']  // String IDs
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'product');

        // Should convert string IDs to integers
        $this->assertEquals([1, 2, 3], $result['relationships']['category']);  // category, not categories
    }

    public function testNormalizeWithMixedDataTypes(): void
    {
        $payload = [
            'name' => 'Product Name',
            'price' => 99.99,
            'active' => true,
            'stock_count' => 50,
            'tags' => ['tag1', 'tag2'],  // Regular array, not _ids
            'metadata' => ['key' => 'value'],
            'brand_id' => '5',  // String ID
            'category_ids' => [1, 2, 3]
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'product');

        // Attributes
        $this->assertEquals('Product Name', $result['attributes']['name']);
        $this->assertEquals(99.99, $result['attributes']['price']);
        $this->assertTrue($result['attributes']['active']);
        $this->assertEquals(50, $result['attributes']['stock_count']);
        $this->assertEquals(['tag1', 'tag2'], $result['attributes']['tags']);
        $this->assertEquals(['key' => 'value'], $result['attributes']['metadata']);

        // Relationships
        $this->assertEquals(5, $result['relationships']['brand']);  // Converted to int
        $this->assertEquals([1, 2, 3], $result['relationships']['category']);  // category, not categories
    }

    public function testNormalizeJsonApiWithMinimalData(): void
    {
        $payload = [
            'data' => [
                'type' => 'user',
                'attributes' => [
                    'name' => 'John Doe'
                ]
            ]
        ];

        $result = $this->normalizer->normalize($payload, 'application/vnd.api+json', 'user');

        $this->assertEquals('John Doe', $result['attributes']['name']);
        $this->assertArrayHasKey('relationships', $result);
        $this->assertEmpty($result['relationships']);
    }

    public function testNormalizeHandlesEdgeCaseFieldNames(): void
    {
        $payload = [
            'compound_name' => 'Test',
            'some_complex_field_id' => 10,
            'multi_word_category_ids' => [1, 2],
            'field_with_numbers_123_id' => 5,
            'numbers_456_category_ids' => [3, 4],
            'ends_with_id_but_not_relationship' => 'not_a_relationship_because_no_underscore'
        ];

        $result = $this->normalizer->normalize($payload, 'application/json', 'test');

        // Check attributes
        $this->assertEquals('Test', $result['attributes']['compound_name']);
        $this->assertEquals('not_a_relationship_because_no_underscore', $result['attributes']['ends_with_id_but_not_relationship']);

        // Check relationships (note: _ids becomes singular form)
        $this->assertEquals(10, $result['relationships']['some_complex_field']);
        $this->assertEquals([1, 2], $result['relationships']['multi_word_category']);
        $this->assertEquals(5, $result['relationships']['field_with_numbers_123']);
        $this->assertEquals([3, 4], $result['relationships']['numbers_456_category']);
    }

    public function testNormalizeJsonDirectly(): void
    {
        // Test various scenarios that exercise the normalizeJson method
        // (by using unsupported content type to force fallback)
        
        // Simple case with just attributes
        $payload = ['name' => 'Product', 'price' => 99.99];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => ['name' => 'Product', 'price' => 99.99],
            'relationships' => []
        ];
        $this->assertEquals($expected, $result);

        // Single relationship (to-one)
        $payload = ['name' => 'Product', 'brand_id' => 5];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => ['name' => 'Product'],
            'relationships' => ['brand' => 5]
        ];
        $this->assertEquals($expected, $result);

        // Multiple relationships (to-many)
        $payload = ['name' => 'Product', 'category_ids' => [1, 2, 3]];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => ['name' => 'Product'],
            'relationships' => ['category' => [1, 2, 3]]
        ];
        $this->assertEquals($expected, $result);

        // Mixed attributes and relationships
        $payload = [
            'name' => 'Product',
            'price' => 99.99,
            'brand_id' => 5,
            'category_ids' => [1, 2],
            'description' => 'A great product'
        ];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => [
                'name' => 'Product',
                'price' => 99.99,
                'description' => 'A great product'
            ],
            'relationships' => [
                'brand' => 5,
                'category' => [1, 2]
            ]
        ];
        $this->assertEquals($expected, $result);

        // Null relationship
        $payload = ['name' => 'Product', 'brand_id' => null];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => ['name' => 'Product'],
            'relationships' => ['brand' => null]
        ];
        $this->assertEquals($expected, $result);

        // String IDs (should be converted to int)
        $payload = ['name' => 'Product', 'brand_id' => '5', 'category_ids' => ['1', '2']];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => ['name' => 'Product'],
            'relationships' => [
                'brand' => 5,
                'category' => [1, 2]
            ]
        ];
        $this->assertEquals($expected, $result);

        // Edge case: field names that contain but don't end with _id/_ids
        $payload = [
            'name' => 'Product',
            'model_id_number' => '12345',  // Contains _id but doesn't end with it
            'category_ids_backup' => [1, 2],  // Contains _ids but doesn't end with it
            'brand_id' => 5,  // Proper relationship
        ];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => [
                'name' => 'Product',
                'model_id_number' => '12345',
                'category_ids_backup' => [1, 2]
            ],
            'relationships' => [
                'brand' => 5
            ]
        ];
        $this->assertEquals($expected, $result);

        // Empty arrays and complex structures
        $payload = [
            'name' => 'Product',
            'tags' => [],
            'metadata' => ['key' => 'value'],
            'category_ids' => []
        ];
        $result = $this->normalizer->normalize($payload, 'text/plain', 'product');
        $expected = [
            'attributes' => [
                'name' => 'Product', 
                'tags' => [],
                'metadata' => ['key' => 'value']
            ],
            'relationships' => [
                'category' => []
            ]
        ];
        $this->assertEquals($expected, $result);
    }
}