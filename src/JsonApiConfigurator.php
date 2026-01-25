<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Modufolio\JsonApi\Filter\FilterInterface;
use Modufolio\JsonApi\Filter\FilterRegistry;
use Modufolio\JsonApi\Filter\JsonApiFilterHandler;

class JsonApiConfigurator
{
    /**
     * @var array<int, class-string<JsonApiResource>>
     */
    private array $entities = [];

    /**
     * @var array<string, array<FilterInterface>>
     */
    private array $filters = [];

    /**
     * @var array<string, array>|null
     */
    private ?array $config = null;

    /**
     * Register entity classes
     *
     * @param array<int, class-string<JsonApiResource>> $entities
     * @return self
     */
    public function entities(array $entities): self
    {
        $this->entities = $entities;
        $this->config = null; // Clear cache
        return $this;
    }

    /**
     * Add a single entity
     *
     * @param class-string<JsonApiResource> $entityClass
     * @return self
     */
    public function addEntity(string $entityClass): self
    {
        if (!in_array($entityClass, $this->entities, true)) {
            $this->entities[] = $entityClass;
            $this->config = null; // Clear cache
        }
        return $this;
    }

    /**
     * Get all registered entities
     *
     * @return array<int, class-string<JsonApiResource>>
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    /**
     * Register filters for an entity
     *
     * @param class-string $entityClass
     * @param array<FilterInterface> $filters
     * @return self
     */
    public function filters(string $entityClass, array $filters): self
    {
        $this->filters[$entityClass] = $filters;
        return $this;
    }

    /**
     * Get all registered filters
     *
     * @return array<string, array<FilterInterface>>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Build configuration from all registered entities
     *
     * @return array<string, array>
     */
    public function buildConfig(): array
    {
        // If config was manually set via resource() method, return it
        if (!empty($this->config)) {
            return $this->config;
        }

        // Otherwise build from entity classes
        $config = [];

        foreach ($this->entities as $entityClass) {
            if (!is_subclass_of($entityClass, JsonApiResource::class)) {
                continue;
            }

            $config[$entityClass] = [
                'resource_key' => $entityClass::getResourceKey(),
                'fields' => $entityClass::getApiFields(),
                'relationships' => $entityClass::getApiRelationships(),
                'operations' => $entityClass::getApiOperations(),
            ];
        }

        $this->config = $config;

        return $config;
    }

    /**
     * Build filter registry for all configured entities
     *
     * @return FilterRegistry
     */
    public function buildFilterRegistry(): FilterRegistry
    {
        $registry = new FilterRegistry();
        $config = $this->buildConfig();

        // Register configured filters for each entity
        foreach (array_keys($config) as $entityClass) {
            // Always register the default JsonApiFilterHandler first
            $registry->register($entityClass, new JsonApiFilterHandler());

            // Register any custom filters configured for this entity
            if (isset($this->filters[$entityClass])) {
                foreach ($this->filters[$entityClass] as $filter) {
                    $registry->register($entityClass, $filter);
                }
            }
        }

        return $registry;
    }

    /**
     * Configure a resource with fluent API
     *
     * @param class-string $entityClass
     * @return ResourceConfigurator
     */
    public function resource(string $entityClass): ResourceConfigurator
    {
        // Initialize config if null, but don't clear existing config
        if ($this->config === null) {
            $this->config = [];
        }
        return new ResourceConfigurator($this, $entityClass);
    }

    /**
     * Set resource configuration
     *
     * @internal Used by ResourceConfigurator
     */
    public function setResourceConfig(string $entityClass, array $config): void
    {
        // Add entity if not already added
        if (!in_array($entityClass, $this->entities, true)) {
            $this->entities[] = $entityClass;
        }

        // Store custom config if provided
        if ($this->config === null) {
            $this->config = [];
        }
        $this->config[$entityClass] = $config;
    }

    /**
     * Clear cached configuration
     *
     * @return self
     */
    public function clearCache(): self
    {
        $this->config = null;
        return $this;
    }
}

/**
 * Fluent resource configurator
 */
class ResourceConfigurator
{
    private string $resourceKey = '';
    private array $fields = [];
    private array $relationships = [];
    private array $operations = [];

    public function __construct(
        private JsonApiConfigurator $configurator,
        private string $entityClass
    ) {
    }

    public function key(string $key): self
    {
        $this->resourceKey = $key;
        $this->save();
        return $this;
    }

    public function fields(array $fields): self
    {
        $this->fields = $fields;
        $this->save();
        return $this;
    }

    public function relationships(array $relationships): self
    {
        $this->relationships = $relationships;
        $this->save();
        return $this;
    }

    public function operations(array $operations): self
    {
        $this->operations = $operations;
        $this->save();
        return $this;
    }

    private function save(): void
    {
        $this->configurator->setResourceConfig($this->entityClass, [
            'resource_key' => $this->resourceKey,
            'fields' => $this->fields,
            'relationships' => $this->relationships,
            'operations' => $this->operations,
        ]);
    }
}
