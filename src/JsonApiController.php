<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Doctrine\Common\Collections\Collection;
use InvalidArgumentException;
use Modufolio\JsonApi\Document\ErrorObject;
use Modufolio\JsonApi\Document\JsonApiDocument;
use Modufolio\JsonApi\Document\ResourceIdentifierObject;
use Modufolio\JsonApi\Document\ResourceObject;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;
use Modufolio\JsonApi\Helpers\Str;
use Modufolio\JsonApi\Http\ResponseFactory;
use Doctrine\ORM\EntityManagerInterface;
use Negotiation\Exception\Exception;
use Negotiation\Negotiator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class JsonApiController
{
    private readonly array $config;
    private readonly JsonApiUrlParser $parser;
    private readonly FilterRegistry $filterRegistry;
    private readonly JsonApiRequestDeserializer $deserializer;
    private readonly InputNormalizer $inputNormalizer;
    private readonly Negotiator $negotiator;

    private const SUPPORTED_CONTENT_TYPES = [
        'application/vnd.api+json',
        'application/json',
    ];

    private const JSON_API_MEDIA_TYPE = 'application/vnd.api+json';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly ResponseFactory $responseFactory,
        private readonly string $configPath,
        ?FilterRegistry $filterRegistry = null,
    ) {
        // Load and execute JSON API configuration file
        $configurator = new JsonApiConfigurator();

        $config = require $configPath;
        $config($configurator);

        // Build config from configurator
        $this->config = $configurator->buildConfig();

        // Initialize parser with full config
        $this->parser = new JsonApiUrlParser($this->config);
        $this->filterRegistry = $filterRegistry ?? $this->createDefaultFilterRegistry();
        $this->deserializer = new JsonApiRequestDeserializer();
        $this->inputNormalizer = new InputNormalizer();
        $this->negotiator = new Negotiator();
    }

    /**
     * Create a default filter registry with standard JSON:API filter handler
     */
    private function createDefaultFilterRegistry(): FilterRegistry
    {
        $registry = new FilterRegistry();

        // Register the default JSON:API filter handler for all configured resources
        foreach (array_keys($this->config) as $entityClass) {
            $registry->register($entityClass, new JsonApiFilterHandler());
        }

        return $registry;
    }

    public function handle(ServerRequestInterface $request, string $entityClass, string $operation, ?int $id = null, ?string $relationship = null): ResponseInterface
    {
        // Validate entity class is configured
        if (!isset($this->config[$entityClass])) {
            return $this->errorResponse(
                "Entity class '{$entityClass}' is not configured for JSON:API",
                400
            );
        }

        // Validate Content-Type header for requests with body (POST, PATCH, PUT)
        if (in_array($request->getMethod(), ['POST', 'PATCH', 'PUT'])) {
            $contentType = $request->getHeaderLine('Content-Type');
            if (!$this->isValidContentType($contentType)) {
                return $this->errorResponse(
                    'Unsupported Content-Type. Supported types: application/vnd.api+json, application/json',
                    415
                );
            }
        }

        // Validate Accept header for JSON:API requests only
        $accept = $request->getHeaderLine('Accept');
        $contentType = $request->getHeaderLine('Content-Type');
        $isJsonApiRequest = str_contains($contentType, 'application/vnd.api+json');

        if ($isJsonApiRequest && $accept && !$this->isValidAcceptHeader($accept)) {
            return $this->errorResponse(
                'Servers MUST respond with a 406 Not Acceptable status code if a request\'s Accept header contains the JSON:API media type and all instances of that media type are modified with media type parameters.',
                406
            );
        }

        return match ($operation) {
            'index' => $this->index($request, $entityClass),
            'show' => $this->show($request, $entityClass, $id),
            'create' => $this->create($request, $entityClass),
            'update' => $this->update($request, $entityClass, $id),
            'delete' => $this->delete($entityClass, $id),
            'related' => $this->related($request, $entityClass, $id, $relationship),
            default => $this->errorResponse('Operation not supported', 400),
        };
    }

    private function index(ServerRequestInterface $request, string $entityClass): ResponseInterface
    {
        try {
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

            $data = $result['data'] ?? $result;
            $total = $result['total'] ?? count($data);
            $page = $params->page['number'] ?? 1;
            $perPage = $params->page['size'] ?? 25;

            $resourceKey = $this->config[$entityClass]['resource_key'];

            // Create document with proper resource objects
            $document = new JsonApiDocument();
            $resources = [];

            foreach ($data as $item) {
                $resources[] = $this->createResourceObject($item, $resourceKey);
            }

            $document->setData($resources);

            // Add pagination meta
            $lastPage = (int)ceil($total / $perPage);
            $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to = min($page * $perPage, $total);

            $document->setMeta([
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ]);

            // Add pagination links
            $uri = $request->getUri();
            $baseUrl = $uri->getScheme() . '://' . $uri->getHost();

            // Add port if non-standard
            $port = $uri->getPort();
            if ($port && (($uri->getScheme() === 'http' && $port !== 80) || ($uri->getScheme() === 'https' && $port !== 443))) {
                $baseUrl .= ':' . $port;
            }

            // Add path without query string and trailing slash
            $baseUrl .= rtrim($uri->getPath(), '/');

            $queryString = $this->buildQueryString($params);
            $document->setLinks($this->buildPaginationLinks($baseUrl, $queryString, $page, $lastPage, $perPage));

            return $this->jsonApiResponse($document);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            // In development/testing, show the actual error
            $message = getenv('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            return $this->errorResponse($message, 500);
        }
    }

    private function show(ServerRequestInterface $request, string $entityClass, ?int $id): ResponseInterface
    {
        try {
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
                $resourceKey = $this->config[$entityClass]['resource_key'];
                return $this->errorResponse(ucfirst($resourceKey) . " not found", 404);
            }

            $resourceKey = $this->config[$entityClass]['resource_key'];
            // executeShow now returns an array with one item in JSON:API format
            $item = $result[0];

            $document = new JsonApiDocument();
            $document->setData($this->createResourceObject($item, $resourceKey));

            return $this->jsonApiResponse($document);
        } catch (\Exception $e) {
            // In development/testing, show the actual error
            $message = getenv('APP_ENV') === 'production' ? 'Internal server error' : $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            return $this->errorResponse($message, 500);
        }
    }

    private function create(ServerRequestInterface $request, string $entityClass): ResponseInterface
    {
        try {
            $payload = json_decode($request->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
            }

            $resourceKey = $this->config[$entityClass]['resource_key'];
            $contentType = $request->getHeaderLine('Content-Type');

            // Normalize input based on content type (supports both JSON:API and plain JSON)
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
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function update(ServerRequestInterface $request, string $entityClass, ?int $id): ResponseInterface
    {
        try {
            $entity = $this->em->getRepository($entityClass)->find($id);

            if (!$entity) {
                $resourceKey = $this->config[$entityClass]['resource_key'];
                return $this->errorResponse(ucfirst($resourceKey) . " not found", 404);
            }

            $payload = json_decode($request->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('Invalid JSON: ' . json_last_error_msg(), 400);
            }

            $resourceKey = $this->config[$entityClass]['resource_key'];
            $contentType = $request->getHeaderLine('Content-Type');

            // Normalize input based on content type (supports both JSON:API and plain JSON)
            $normalized = $this->inputNormalizer->normalize($payload, $contentType, $resourceKey);
            $data = $this->inputNormalizer->mergeData($normalized);

            $this->populateEntity($entity, $data, $entityClass);

            $violations = $this->validator->validate($entity);

            if ($violations->count() > 0) {
                return $this->validationErrorResponse($violations);
            }

            $this->em->flush();

            $document = new JsonApiDocument();
            $document->setData($this->transformEntityToResourceObject($entity, $resourceKey, $entityClass));

            return $this->jsonApiResponse($document);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function delete(string $entityClass, ?int $id): ResponseInterface
    {
        try {
            $entity = $this->em->getRepository($entityClass)->find($id);

            if (!$entity) {
                $resourceKey = $this->config[$entityClass]['resource_key'];
                return $this->errorResponse(ucfirst($resourceKey) . " not found", 404);
            }

            // Use soft delete if available
            if (method_exists($entity, 'softDelete')) {
                $entity->softDelete();
            } else {
                $this->em->remove($entity);
            }

            $this->em->flush();

            return $this->responseFactory->empty(204);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    private function related(ServerRequestInterface $request, string $entityClass, ?int $id, ?string $relationship): ResponseInterface
    {
        try {
            $entity = $this->em->getRepository($entityClass)->find($id);

            if (!$entity) {
                $resourceKey = $this->config[$entityClass]['resource_key'];
                return $this->errorResponse(ucfirst($resourceKey) . " not found", 404);
            }

            $getterMethod = 'get' . ucfirst($relationship);
            if (!method_exists($entity, $getterMethod)) {
                return $this->errorResponse("Relationship '$relationship' not found", 404);
            }

            $relatedData = $entity->$getterMethod();

            // Handle collection relationships
            if ($relatedData instanceof Collection) {
                $queryParams = $request->getQueryParams();
                $pagination = JsonApiSerializer::parsePaginationParams($queryParams);

                $relatedArray = $relatedData->toArray();
                $total = count($relatedArray);

                // Manual pagination
                $offset = ($pagination['number'] - 1) * $pagination['size'];
                $items = array_slice($relatedArray, $offset, $pagination['size']);

                // Get target entity class
                $metadata = $this->em->getClassMetadata($entityClass);
                $targetClass = $metadata->getAssociationTargetClass($relationship);
                $targetResourceKey = $this->config[$targetClass]['resource_key'] ?? Str::snake(class_basename($targetClass));

                $document = new JsonApiDocument();
                $resources = [];

                foreach ($items as $item) {
                    $resources[] = $this->transformEntityToResourceObject($item, $targetResourceKey, $targetClass);
                }

                $document->setData($resources);

                // Add pagination meta
                $lastPage = (int)ceil($total / $pagination['size']);
                $from = $total > 0 ? (($pagination['number'] - 1) * $pagination['size']) + 1 : 0;
                $to = min($pagination['number'] * $pagination['size'], $total);

                $document->setMeta([
                    'total' => $total,
                    'per_page' => $pagination['size'],
                    'current_page' => $pagination['number'],
                    'last_page' => $lastPage,
                    'from' => $from,
                    'to' => $to,
                ]);

                return $this->jsonApiResponse($document);
            }

            // Handle single relationships
            if ($relatedData) {
                $relatedClass = get_class($relatedData);
                $resourceKey = $this->config[$relatedClass]['resource_key'] ?? Str::snake(class_basename($relatedClass));

                $document = new JsonApiDocument();
                $document->setData($this->transformEntityToResourceObject($relatedData, $resourceKey, $relatedClass));

                return $this->jsonApiResponse($document);
            }

            $document = new JsonApiDocument();
            $document->setData(null);

            return $this->jsonApiResponse($document);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    private function createResourceObject(array $item, string $resourceKey): ResourceObject
    {
        $id = (string)($item['id'] ?? '');
        $resource = new ResourceObject($resourceKey, $id);

        // Set attributes
        $attributes = $item['attributes'] ?? [];
        if (!empty($attributes)) {
            $resource->setAttributes($attributes);
        }

        // Set relationships
        $relationships = $item['relationships'] ?? [];
        if (!empty($relationships)) {
            $resource->setRelationships($relationships);
        }

        // Add self link
        if ($id) {
            $resource->setLinks([
                'self' => "/api/{$resourceKey}/{$id}"
            ]);
        }

        return $resource;
    }

    private function transformEntityToResourceObject(object $entity, string $resourceKey, string $entityClass): ResourceObject
    {
        $metadata = $this->em->getClassMetadata($entityClass);
        $config = $this->config[$entityClass] ?? [];

        $id = method_exists($entity, 'getId') ? (string)$entity->getId() : null;
        $resource = new ResourceObject($resourceKey, $id);

        // Build attributes
        $attributes = [];
        $fields = $config['fields'] ?? [];

        foreach ($fields as $field) {
            $getterMethod = 'get' . ucfirst($field);
            if (method_exists($entity, $getterMethod)) {
                $value = $entity->$getterMethod();

                // Convert DateTime to string
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }

                $attributes[Str::snake($field)] = $value;
            }
        }

        $resource->setAttributes($attributes);

        // Add self link
        if ($id) {
            $resource->setLinks([
                'self' => "/api/{$resourceKey}/{$id}"
            ]);
        }

        // Build relationships
        foreach ($config['relationships'] ?? [] as $relationship) {
            $getterMethod = 'get' . ucfirst($relationship);
            if (method_exists($entity, $getterMethod)) {
                $relatedData = $entity->$getterMethod();

                // Handle single relationships
                if ($relatedData && !$relatedData instanceof Collection) {
                    $relatedClass = get_class($relatedData);
                    $relatedResourceKey = $this->config[$relatedClass]['resource_key'] ?? Str::snake($this->getClassBasename($relatedClass));
                    $relatedId = method_exists($relatedData, 'getId') ? (string)$relatedData->getId() : null;

                    $identifier = new ResourceIdentifierObject($relatedResourceKey, $relatedId);
                    $links = [
                        'self' => "/api/{$resourceKey}/{$id}/relationships/{$relationship}",
                        'related' => "/api/{$resourceKey}/{$id}/{$relationship}"
                    ];
                    $resource->setToOneRelationship($relationship, $identifier, $links);
                } elseif ($relatedData instanceof Collection) {
                    // Handle collection relationships - add links
                    $links = [
                        'self' => "/api/{$resourceKey}/{$id}/relationships/{$relationship}",
                        'related' => "/api/{$resourceKey}/{$id}/{$relationship}"
                    ];
                    $resource->setToManyRelationship($relationship, [], $links);
                } else {
                    $links = [
                        'self' => "/api/{$resourceKey}/{$id}/relationships/{$relationship}",
                        'related' => "/api/{$resourceKey}/{$id}/{$relationship}"
                    ];
                    $resource->setToOneRelationship($relationship, null, $links);
                }
            }
        }

        return $resource;
    }

    private function populateEntity(object $entity, array $data, string $entityClass): void
    {
        $metadata = $this->em->getClassMetadata($entityClass);

        foreach ($data as $key => $value) {
            // Convert snake_case to camelCase
            $property = Str::camel($key);

            // Skip if not a valid field
            if (!$metadata->hasField($property) && !$metadata->hasAssociation($property)) {
                continue;
            }

            $setterMethod = 'set' . ucfirst($property);

            if (!method_exists($entity, $setterMethod)) {
                continue;
            }

            // Handle associations
            if ($metadata->hasAssociation($property)) {
                $targetEntity = $metadata->getAssociationTargetClass($property);
                $isCollection = $metadata->isCollectionValuedAssociation($property);

                if ($value === null) {
                    $entity->$setterMethod(null);
                } elseif ($isCollection && is_array($value)) {
                    // Handle to-many relationship (array of IDs)
                    $collection = new \Doctrine\Common\Collections\ArrayCollection();
                    foreach ($value as $relatedId) {
                        $relatedEntity = $this->em->getRepository($targetEntity)->find($relatedId);
                        if ($relatedEntity) {
                            $collection->add($relatedEntity);
                        }
                    }
                    $entity->$setterMethod($collection);
                } elseif (!$isCollection && !is_array($value)) {
                    // Handle to-one relationship (single ID)
                    $relatedEntity = $this->em->getRepository($targetEntity)->find($value);
                    if ($relatedEntity) {
                        $entity->$setterMethod($relatedEntity);
                    }
                } else {
                    // Type mismatch: expecting collection but got single value or vice versa
                    throw new InvalidArgumentException(
                        sprintf(
                            'Relationship "%s" type mismatch: expected %s, got %s',
                            $property,
                            $isCollection ? 'array' : 'single value',
                            is_array($value) ? 'array' : 'single value'
                        )
                    );
                }
                continue;
            }

            // Handle regular fields
            $entity->$setterMethod($value);
        }
    }

    private function validationErrorResponse($violations): ResponseInterface
    {
        $document = new JsonApiDocument();
        $errors = [];

        foreach ($violations as $violation) {
            $error = new ErrorObject();
            $error->setStatus(422);
            $error->setTitle('Validation Error');
            $error->setDetail((string)$violation->getMessage());
            $error->setSource([
                'pointer' => '/data/attributes/' . Str::snake($violation->getPropertyPath())
            ]);

            $errors[] = $error;
        }

        $document->setErrors($errors);

        return $this->jsonApiResponse($document, 422);
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

    /**
     * Validate Content-Type header using content negotiation
     *
     * Supports both JSON:API and plain JSON formats
     *
     * @param string $contentType
     * @return bool
     * @throws Exception
     */
    private function isValidContentType(string $contentType): bool
    {
        if (empty($contentType)) {
            return false;
        }

        // Use negotiator to check if content type is supported
        $mediaType = $this->negotiator->getBest($contentType, self::SUPPORTED_CONTENT_TYPES);

        if ($mediaType === null) {
            return false;
        }

        // For JSON:API, validate that no unsupported media type parameters are present
        if ($mediaType->getType() === self::JSON_API_MEDIA_TYPE) {
            $parameters = $mediaType->getParameters();

            // JSON:API spec only allows 'ext' and 'profile' parameters
            // charset is allowed for both formats
            foreach (array_keys($parameters) as $param) {
                if (!in_array($param, ['ext', 'profile', 'charset'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate Accept header using content negotiation
     *
     * According to JSON:API spec, the Accept header must not contain media type parameters
     * except for 'ext' and 'profile' (and 'q' for quality)
     *
     * @param string $accept
     * @return bool
     */
    private function isValidAcceptHeader(string $accept): bool
    {
        if (empty($accept)) {
            return true;
        }

        // If Accept header doesn't contain JSON:API media type, it's valid
        if (!str_contains($accept, self::JSON_API_MEDIA_TYPE)) {
            return true;
        }

        try {
            // Parse all media types in the Accept header
            $mediaTypes = $this->negotiator->getBest($accept, [self::JSON_API_MEDIA_TYPE]);

            if ($mediaTypes === null) {
                // JSON:API not in Accept header or not acceptable
                return true;
            }

            // Validate JSON:API media type parameters
            $parameters = $mediaTypes->getParameters();

            // Only 'ext', 'profile', and 'q' (quality) are allowed
            foreach (array_keys($parameters) as $param) {
                if (!in_array($param, ['ext', 'profile', 'q'], true)) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            // Invalid Accept header format
            return false;
        }
    }

    /**
     * Build pagination links for JSON:API responses
     *
     * @param string $baseUrl Base URL for the resource
     * @param string $queryString Additional query parameters
     * @param int $currentPage Current page number
     * @param int $lastPage Last page number
     * @param int $perPage Items per page
     * @return array<string, string|null>
     */
    private function buildPaginationLinks(string $baseUrl, string $queryString, int $currentPage, int $lastPage, int $perPage): array
    {
        $buildUrl = function (int $page) use ($baseUrl, $queryString, $perPage): string {
            $params = [];

            // Add page parameters
            $params[] = "page[number]={$page}";
            $params[] = "page[size]={$perPage}";

            // Add other query parameters if present
            if ($queryString) {
                $params[] = $queryString;
            }

            return $baseUrl . '?' . implode('&', $params);
        };

        return [
            'self' => $buildUrl($currentPage),
            'first' => $buildUrl(1),
            'last' => $buildUrl($lastPage),
            'prev' => $currentPage > 1 ? $buildUrl($currentPage - 1) : null,
            'next' => $currentPage < $lastPage ? $buildUrl($currentPage + 1) : null,
        ];
    }


    /**
     * Build query string from JSON:API params (excluding pagination)
     *
     * @param JsonApiQueryParams $params
     * @return string
     */
    private function buildQueryString(JsonApiQueryParams $params): string
    {
        $queryParts = [];

        // Add filter parameters
        if (!empty($params->filter)) {
            foreach ($params->filter as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $operator => $val) {
                        // Handle 'in' operator specially - it's an array of values
                        if ($operator === 'in' && is_array($val)) {
                            $queryParts[] = "filter[{$key}][{$operator}]=" . urlencode(implode(',', $val));
                        } elseif ($operator === 'null' || $operator === 'not_null') {
                            // null and not_null don't have values
                            $queryParts[] = "filter[{$key}][{$operator}]=";
                        } else {
                            $queryParts[] = "filter[{$key}][{$operator}]=" . urlencode((string)$val);
                        }
                    }
                } else {
                    $queryParts[] = "filter[{$key}]=" . urlencode((string)$value);
                }
            }
        }

        // Add sort parameters
        if (!empty($params->sort)) {
            $queryParts[] = 'sort=' . urlencode(implode(',', $params->sort));
        }

        // Add include parameters
        if (!empty($params->include)) {
            $queryParts[] = 'include=' . urlencode(implode(',', $params->include));
        }

        // Add fields parameters
        if (!empty($params->fields)) {
            foreach ($params->fields as $type => $fields) {
                if (is_array($fields)) {
                    $queryParts[] = "fields[{$type}]=" . urlencode(implode(',', $fields));
                }
            }
        }

        return implode('&', $queryParts);
    }

    public function getClassBasename(object|string $class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }

}
