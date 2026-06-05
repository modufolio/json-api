# Installation and Setup

This guide walks you through installing and setting up the Modufolio JSON:API library in your PHP application.

## System Requirements

Before installing the library, ensure your system meets the following requirements:

- **PHP**: 8.2 or higher
- **Extensions**: `ext-json`, `ext-pdo` (for database connectivity)
- **Doctrine ORM**: 3.0 or higher
- **PSR-7 Implementation**: Such as `nyholm/psr7` or `guzzlehttp/psr7`
- **PSR-17 Implementation**: HTTP factory implementation

## Installation via Composer

### Basic Installation

```bash
composer require modufolio/json-api
```

### Development Dependencies

For development and testing, you may also want to install additional packages:

```bash
composer require --dev \
    nyholm/psr7 \
    symfony/cache \
    phpunit/phpunit
```

## Basic Configuration

### Entity Mapping

The core of the library is the entity configuration that defines how your Doctrine entities are exposed through the JSON:API:

```php
$config = [
    'Your\Entity\ClassName' => [
        'resource_key'    => 'resource-name',      // JSON:API resource type
        'fields'          => [],                    // Allowed fields
        'relationships'   => [],                    // Allowed relationships
        'operations'      => [],                    // Allowed operations
        'filters'         => [],                    // Custom filter configuration
        'sorts'           => [],                    // Allowed sort fields
        'includes'        => [],                    // Allowed includes
    ],
];
```

### Minimal Setup Example

```php
use App\Entity\Post;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;
use Nyholm\Psr7\ServerRequestFactory;

// Your Doctrine EntityManager
$entityManager = /* ... */;

// Build the config from the fluent configurator
$configurator = new JsonApiConfigurator();
$configurator->resource(Post::class)
    ->key('posts')
    ->fields(['id', 'title', 'content'])
    ->relationships(['author', 'comments'])
    ->operations(['index' => true, 'show' => true]);

$config = $configurator->buildConfig();

// Create a PSR-7 request from globals
$request = ServerRequestFactory::fromGlobals();

// Parse query params (filter/sort/include/page/fields) from the request
$params = (new JsonApiUrlParser($config))->parse($request, Post::class);

// Build and run the query
$builder = new JsonApiQueryBuilder(
    $config,
    $entityManager,
    $entityManager->getConnection(),
    Post::class,
);

$result = $builder
    ->applyParams($params)
    ->operation('index')
    ->withTotalCount()
    ->get();

// Wrap the rows in a JSON:API document
$document = new JsonApiDocument();
$document->setData(array_map(
    fn (array $row) => (new ResourceObject('posts', (string) $row['id']))
        ->setAttributes($row['attributes'] ?? []),
    $result['data'] ?? $result,
));

header('Content-Type: application/vnd.api+json');
echo json_encode($document->toArray());
```

> `JsonApiQueryBuilder` takes five constructor arguments: the config array, the `EntityManagerInterface`, the DBAL `Connection`, the resource (entity) class, and an optional `FilterRegistry`. The `JsonApiController` below shows the full request/response cycle with documents, validation, and error handling.

## Complete Controller Example

Here's a complete controller implementation based on the library's test fixtures:

```php
<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Modufolio\JsonApi\Document\ErrorObject;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\Http\ResponseFactory;
use Modufolio\JsonApi\InputNormalizer;
use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ApiController
{
    private readonly array $config;
    private readonly JsonApiUrlParser $parser;
    private readonly FilterRegistry $filterRegistry;
    private readonly InputNormalizer $inputNormalizer;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ResponseFactory $responseFactory,
        string $configPath
    ) {
        // Load configuration
        $configurator = new JsonApiConfigurator();
        $config = require $configPath;
        $config($configurator);
        $this->config = $configurator->buildConfig();

        // Initialize components
        $this->parser = new JsonApiUrlParser($this->config);
        $this->filterRegistry = $this->createFilterRegistry();
        $this->inputNormalizer = new InputNormalizer();
    }

    private function createFilterRegistry(): FilterRegistry
    {
        $registry = new FilterRegistry();
        
        foreach (array_keys($this->config) as $entityClass) {
            $registry->register($entityClass, new JsonApiFilterHandler());
        }
        
        return $registry;
    }

    public function index(ServerRequestInterface $request, string $entityClass): ResponseInterface
    {
        $params = $this->parser->parse($request, $entityClass);

        $builder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $entityClass,
            $this->filterRegistry
        );

        $result = $builder
            ->applyParams($params)
            ->operation('index')
            ->withTotalCount()
            ->get();

        return $this->createCollectionResponse($result, $request, $entityClass, $params);
    }

    public function show(ServerRequestInterface $request, string $entityClass, int $id): ResponseInterface
    {
        $params = $this->parser->parse($request, $entityClass);
        $params->id = (string)$id;

        $builder = new JsonApiQueryBuilder(
            $this->config,
            $this->em,
            $this->em->getConnection(),
            $entityClass,
            $this->filterRegistry
        );

        $result = $builder
            ->applyParams($params)
            ->operation('show')
            ->get();

        if (empty($result)) {
            return $this->errorResponse('Resource not found', 404);
        }

        $resourceKey = $this->config[$entityClass]['resource_key'];
        $document = new JsonApiDocument();
        $document->setData($this->createResourceObject($result[0], $resourceKey));

        return $this->jsonApiResponse($document);
    }

    public function create(ServerRequestInterface $request, string $entityClass): ResponseInterface
    {
        $payload = json_decode($request->getBody()->getContents(), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse('Invalid JSON', 400);
        }

        $resourceKey = $this->config[$entityClass]['resource_key'];
        $contentType = $request->getHeaderLine('Content-Type');

        // Normalize input (supports both JSON:API and plain JSON)
        $normalized = $this->inputNormalizer->normalize($payload, $contentType, $resourceKey);
        $data = $this->inputNormalizer->mergeData($normalized);

        $entity = new $entityClass();
        $this->populateEntity($entity, $data, $entityClass);

        $violations = $this->validator->validate($entity);
        if ($violations->count() > 0) {
            return $this->validationErrorResponse($violations);
        }

        $this->em->persist($entity);
        $this->em->flush();

        $document = new JsonApiDocument();
        $document->setData($this->transformEntityToResourceObject($entity, $resourceKey, $entityClass));

        return $this->jsonApiResponse($document, 201);
    }

    private function createResourceObject(array $item, string $resourceKey): ResourceObject
    {
        $id = (string)($item['id'] ?? '');
        $resource = new ResourceObject($resourceKey, $id);

        if (!empty($item['attributes'])) {
            $resource->setAttributes($item['attributes']);
        }

        if (!empty($item['relationships'])) {
            $resource->setRelationships($item['relationships']);
        }

        if ($id) {
            $resource->setLinks(['self' => "/api/{$resourceKey}/{$id}"]);
        }

        return $resource;
    }

    private function errorResponse(string $message, int $status): ResponseInterface
    {
        $document = new JsonApiDocument();
        $error = new ErrorObject();
        $error->setStatus($status);
        $error->setTitle('Error');
        $error->setDetail($message);
        $document->setErrors([$error]);

        return $this->jsonApiResponse($document, $status);
    }

    private function jsonApiResponse(JsonApiDocument $document, int $status = 200): ResponseInterface
    {
        return $this->responseFactory->json(
            $document->toArray(),
            $status,
            ['Content-Type' => 'application/vnd.api+json']
        );
    }
}
```

## Configuration File Example

Based on the test fixtures, here's a complete configuration file (`config/json_api.php`):

```php
<?php

use App\Entity\Article;
use App\Entity\Comment;
use App\Entity\User;
use Modufolio\JsonApi\JsonApiConfigurator;

return function (JsonApiConfigurator $api) {
    // Configure Article entity
    $api->resource(Article::class)
        ->key('articles')
        ->fields(['id', 'title', 'content', 'publishedAt', 'updatedAt'])
        ->relationships(['author', 'comments', 'tags'])
        ->operations([
            'index'  => true,
            'show'   => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ]);

    // Configure User entity
    $api->resource(User::class)
        ->key('users')
        ->fields(['id', 'name', 'email', 'createdAt'])
        ->relationships(['articles', 'profile'])
        ->operations([
            'index'  => true,
            'show'   => true,
            'update' => true,
        ]);

    // Configure Comment entity
    $api->resource(Comment::class)
        ->key('comments')
        ->fields(['id', 'content', 'createdAt'])
        ->relationships(['article', 'author'])
        ->operations([
            'index'  => true,
            'show'   => true,
            'create' => true,
            'update' => true,
            'delete' => true,
        ]);
};
```

A resource config holds exactly four things: `key()` (the JSON:API `type`), `fields()`, `relationships()`, and `operations()`. `operations()` is an **associative `name => bool` map** — `index`, `show`, `create`, `update`, `delete` — and defaults to `['index' => true]` when omitted.

There is no `sorts()` or `includes()` config. **Sorting and inclusion are request-time concerns**, controlled by the `fields()` and `relationships()` allow-lists: a client may sort by any allowed field and include any allowed relationship. Filters are not part of the resource config either — they are registered separately through a `FilterRegistry` (see below and the [Filtering](filtering.md) guide).

### Registering filters

Filters are objects implementing `FilterInterface`, attached per entity through `JsonApiConfigurator::filters()` (or directly on a `FilterRegistry`). The built-in `JsonApiFilterHandler` covers the standard JSON:API operators; `SearchFilter` and `DateFilter` add field-specific behaviour.

```php
use App\Entity\Article;
use Modufolio\JsonApi\Filter\SearchFilter;
use Modufolio\JsonApi\Filter\SearchStrategy;

$api->filters(Article::class, [
    // Declare only the field-specific filters. buildFilterRegistry() adds the
    // catch-all and scopes it off these fields — see the Filtering guide.
    new SearchFilter([
        'title' => SearchStrategy::PARTIAL,
        'slug'  => SearchStrategy::EXACT,
    ]),
]);

// Later: $registry = $configurator->buildFilterRegistry();
```

## Advanced Query Builder Usage

Based on the query builder tests, here are advanced usage patterns:

The fluent setters take arrays, not positional arguments. Each call sets (replaces) that part of the query:

```php
// Complex filtering and sorting
$builder = new JsonApiQueryBuilder($config, $em, $connection, $entityClass, $filterRegistry);

$result = $builder
    ->filter([
        'title'       => ['like' => '%API%'],
        'status'      => ['in' => ['published', 'featured']],
        'publishedAt' => ['gte' => '2024-01-01'],
    ])
    ->sort(['-publishedAt', 'title'])          // publishedAt desc, then title asc
    ->include(['author', 'comments.author'])
    ->fields([                                 // sparse fieldsets, keyed by resource type
        'articles' => ['id', 'title', 'publishedAt'],
        'users'    => ['id', 'name'],
    ])
    ->page(1, 20)
    ->operation('index')
    ->withTotalCount()
    ->get();

// Sparse fieldsets only
$builder = new JsonApiQueryBuilder($config, $em, $connection, $entityClass, $filterRegistry);

$result = $builder
    ->fields([
        'articles' => ['id', 'title'],
        'users'    => ['id', 'name'],
    ])
    ->include(['author'])
    ->operation('index')
    ->get();

// Fetching a single resource by id
$builder = new JsonApiQueryBuilder($config, $em, $connection, $entityClass, $filterRegistry);

$result = $builder
    ->withId('123')
    ->operation('show')
    ->get();
```

> In a real application you usually build the whole query from the incoming request in one step with `->applyParams($parser->parse($request, $entityClass))`, then add only `->operation(...)`. The explicit setters above are for cases where you construct a query by hand. Pagination uses `page(int $number, int $size)` — there is no `paginate()` method.

## Content Negotiation

The library supports both JSON:API and plain JSON formats:

```php
// JSON:API format (Content-Type: application/vnd.api+json)
$payload = [
    'data' => [
        'type' => 'articles',
        'attributes' => [
            'title' => 'My Article',
            'content' => 'Article content'
        ],
        'relationships' => [
            'author' => [
                'data' => ['type' => 'users', 'id' => '1']
            ]
        ]
    ]
];

// Plain JSON format (Content-Type: application/json)
$payload = [
    'title' => 'My Article',
    'content' => 'Article content',
    'author_id' => 1
];

// Both formats are automatically normalized by InputNormalizer
$normalized = $inputNormalizer->normalize($payload, $contentType, 'articles');
```

## Verification

To verify your installation, create a simple test script:

```php
<?php

require_once 'vendor/autoload.php';

use Modufolio\JsonApi\JsonApiQueryBuilder;

if (class_exists(JsonApiQueryBuilder::class)) {
    echo "Modufolio JSON:API is installed successfully!\n";
    echo "Version: " . \Composer\InstalledVersions::getVersion('modufolio/json-api') . "\n";
} else {
    echo "Installation failed.\n";
}
```

## Testing Your Setup

Create a simple test to verify everything works:

```php
<?php
// test_api.php

require_once 'vendor/autoload.php';

use Doctrine\ORM\EntityManager;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Nyholm\Psr7\ServerRequest;

// Your Doctrine EntityManager setup
$entityManager = /* your entity manager */;

// Test configuration (the raw array shape produced by JsonApiConfigurator::buildConfig())
$config = [
    'App\Entity\Article' => [
        'resource_key' => 'articles',
        'fields' => ['id', 'title', 'content'],
        'operations' => ['index' => true, 'show' => true],
    ],
];

// Test URL parsing
$parser = new JsonApiUrlParser($config);
$request = new ServerRequest('GET', '/api/articles?filter[title]=test&sort=-created_at&page[number]=1&page[size]=10');

try {
    $params = $parser->parse($request, 'App\Entity\Article');
    
    echo "✓ URL parsing works\n";
    echo "Filters: " . print_r($params->filter, true);
    echo "Sort: " . print_r($params->sort, true);
    echo "Pagination: " . print_r($params->page, true);
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

// Test query building
$filterRegistry = new FilterRegistry();
$filterRegistry->register('App\Entity\Article', new JsonApiFilterHandler());

$builder = new JsonApiQueryBuilder(
    $config,
    $entityManager,
    $entityManager->getConnection(),
    'App\Entity\Article',
    $filterRegistry
);

try {
    $sql = $builder
        ->filter(['title' => ['like' => '%test%']])
        ->sort(['-createdAt'])
        ->page(1, 10)
        ->operation('index')
        ->toSql();

    echo "✓ Query building works\n";
    echo "SQL: " . $sql . "\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nSetup complete! 🚀\n";
```

## Performance Considerations

Based on the test suite, here are important performance tips:

### Query Optimization

```php
// Sparse fieldsets keep the SELECT narrow and avoid loading unneeded columns
$builder = new JsonApiQueryBuilder($config, $em, $connection, $entityClass, $filterRegistry);

$result = $builder
    ->include(['author', 'comments'])      // related data fetched via JOIN
    ->fields([
        'articles' => ['id', 'title', 'publishedAt'],
    ])
    ->operation('index')
    ->get();
```

### Pagination

```php
// Always paginate large datasets. page(int $number, int $size)
$result = $builder
    ->page(1, 25)        // page 1, 25 items per page
    ->withTotalCount()   // include the total count alongside the rows
    ->operation('index')
    ->get();

echo "Total items: " . $result['total'];
echo "Current page items: " . count($result['data']);
```

### Filtering

```php
// Filter on indexed columns where possible
$result = $builder
    ->filter([
        'status'      => ['eq' => 'published'],   // ensure status is indexed
        'publishedAt' => ['gte' => '2024-01-01'], // date index
    ])
    ->operation('index')
    ->get();
```

## Next Steps

Now that you have the library installed, proceed to:

- [Configuration](configuration.md) - Detailed configuration options
- [Basic Usage](basic-usage.md) - Start building your first JSON:API endpoints
- [Query Builder](query-builder.md) - Learn about the query building system