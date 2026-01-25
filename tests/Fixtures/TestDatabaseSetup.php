<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class TestDatabaseSetup
{
    private static ?EntityManager $entityManager = null;

    public static function createEntityManager(): EntityManager
    {
        if (self::$entityManager !== null) {
            return self::$entityManager;
        }

        $configurator = new OrmConfigurator();

        // Configure connection for SQLite in-memory
        $configurator->connection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        // Set entity paths
        $configurator->entities(__DIR__ . '/Entity');

        // Setup cache (using ArrayAdapter for tests - simple and fast)
        $cache = new ArrayAdapter();

        $config = $configurator->ormConfig;
        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);
        $config->setResultCache($cache);
        $config->setProxyDir(sys_get_temp_dir() . '/json-api-test-proxies');
        $config->setProxyNamespace('JsonApiTestProxies');
        $config->setAutoGenerateProxyClasses(true);
        $config->setMetadataDriverImpl(new AttributeDriver($configurator->entityPaths));

        // Create connection
        $connection = DriverManager::getConnection(
            $configurator->connectionParams,
            $configurator->dbalConfig
        );

        // Create EntityManager
        $entityManager = new EntityManager($connection, $config);

        // Create schema
        $schemaTool = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($classes);

        self::$entityManager = $entityManager;

        return $entityManager;
    }

    public static function reset(): void
    {
        if (self::$entityManager === null) {
            return;
        }

        $connection = self::$entityManager->getConnection();

        // Drop and recreate schema
        $schemaTool = new SchemaTool(self::$entityManager);
        $classes = self::$entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($classes);
        $schemaTool->createSchema($classes);

        // Clear entity manager
        self::$entityManager->clear();
    }

    public static function close(): void
    {
        if (self::$entityManager !== null) {
            self::$entityManager->close();
            self::$entityManager = null;
        }
    }
}
