<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi\Tests\Fixtures;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\Configuration as OrmConfiguration;

final class OrmConfigurator
{
    public array $connectionParams = [];
    public DbalConfiguration $dbalConfig;
    public OrmConfiguration $ormConfig;
    /** @var string[] */
    public array $entityPaths = [];
    private array $subscribers = [];

    public function __construct()
    {
        $this->dbalConfig = new DbalConfiguration();
        $this->ormConfig = new OrmConfiguration();

        // Enable native lazy objects for PHP 8.4+
        if (PHP_VERSION_ID >= 80400) {
            $this->ormConfig->enableNativeLazyObjects(true);
        }
    }

    public function connection(array $params): self
    {
        $this->connectionParams = $params;
        return $this;
    }

    public function getDbalConfig(): DbalConfiguration
    {
        return $this->dbalConfig;
    }

    public function getOrmConfig(): OrmConfiguration
    {
        return $this->ormConfig;
    }

    public function entities(string ...$paths): self
    {
        $this->entityPaths = [...$this->entityPaths, ...$paths];
        return $this;
    }

    public function addFilter(string $name, string $class): self
    {
        $this->ormConfig->addFilter($name, $class);
        return $this;
    }

    public function middlewares(array $middlewares): self
    {
        $this->dbalConfig->setMiddlewares($middlewares);
        return $this;
    }

    public function addSubscriber(EventSubscriber $subscriber): self
    {
        $this->subscribers[] = $subscriber;
        return $this;
    }

    /**
     * @return EventSubscriber[]
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }
}
