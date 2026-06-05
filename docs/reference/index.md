# API Reference

Reference for the public classes of `modufolio/json-api`, grouped by responsibility. Signatures are listed verbatim; `self` return types denote a fluent (chainable) method.

| Area | Classes | Page |
|------|---------|------|
| Configuration | `JsonApiConfigurator`, `ResourceConfigurator`, `JsonApiResource` | [configuration.md](configuration.md) |
| Request → query | `JsonApiUrlParser`, `JsonApiQueryParams`, `JsonApiQueryBuilder` | [query.md](query.md) |
| Input handling | `InputNormalizer`, `JsonApiRequestDeserializer` | [input.md](input.md) |
| Documents & serialization | `JsonApiDocument`, `ResourceObject`, `ResourceIdentifierObject`, `ErrorObject`, `JsonApiSerializer` | [documents.md](documents.md) |
| Filters | `FilterInterface`, `FilterRegistry`, `JsonApiFilterHandler`, `SearchFilter`, `SearchStrategy`, `DateFilter` | [filters.md](filters.md) |
| HTTP, pagination, utilities | `ResponseFactory`, `JsonApiPaginator`, `Str`, `SafeExpressionBuilder` | [http.md](http.md) |

All classes live under the `Modufolio\JsonApi` namespace (sub-namespaces as shown on each page).

## The shortest possible flow

```php
use App\Entity\Article;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\JsonApiConfigurator;
use Modufolio\JsonApi\JsonApiQueryBuilder;
use Modufolio\JsonApi\JsonApiUrlParser;

$configurator = new JsonApiConfigurator();
$configurator->resource(Article::class)
    ->key('articles')
    ->fields(['id', 'title'])
    ->operations(['index' => true]);
$config = $configurator->buildConfig();

$params = (new JsonApiUrlParser($config))->parse($request, Article::class);

$result = (new JsonApiQueryBuilder($config, $em, $em->getConnection(), Article::class))
    ->applyParams($params)
    ->operation('index')
    ->get();

$document = new JsonApiDocument();
$document->setData(array_map(
    fn (array $row) => (new ResourceObject('articles', (string) $row['id']))->setAttributes($row['attributes'] ?? []),
    $result['data'] ?? $result,
));

echo json_encode($document->toArray());
```

See the guides for narrative explanations: [Installation](../installation.md), [Basic Usage](../basic-usage.md), [Configuration](../configuration.md), [Query Builder](../query-builder.md), [Filtering](../filtering.md).
