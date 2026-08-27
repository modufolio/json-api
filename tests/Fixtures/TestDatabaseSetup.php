<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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

        $configurator->connection(self::connectionParams());

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

        // Create schema. An in-memory SQLite starts empty every run, but a
        // real engine still holds the tables the previous run created, so the
        // drop is what makes the suite repeatable off SQLite.
        $schemaTool = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        self::dropSchema($schemaTool, $classes, $connection);
        $schemaTool->createSchema($classes);

        self::$entityManager = $entityManager;

        return $entityManager;
    }

    /**
     * Where the suite runs.
     *
     * SQLite in memory by default, so a checkout tests with no setup at all.
     * Set DB_DRIVER to run the same suite against a real engine — the point
     * being the places they disagree: booleans, NULL ordering, identifier
     * quoting, and how each renders a bound parameter.
     *
     *   DB_DRIVER=pdo_mysql DB_NAME=json_api_test DB_USER=root vendor/bin/phpunit
     *
     * `docker compose up -d` in the repo root brings up all three.
     *
     * @return array<string, mixed>
     */
    public static function connectionParams(): array
    {
        $driver = getenv('DB_DRIVER') ?: 'pdo_sqlite';

        if ($driver === 'pdo_sqlite') {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        $params = [
            'driver'   => $driver,
            'host'     => getenv('DB_HOST') ?: '127.0.0.1',
            'dbname'   => getenv('DB_NAME') ?: 'json_api_test',
            'user'     => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];

        if (getenv('DB_PORT') !== false && getenv('DB_PORT') !== '') {
            $params['port'] = (int) getenv('DB_PORT');
        }

        // ODBC driver 18 encrypts by default and rejects the self-signed
        // certificate a containerised SQL Server presents, so the connection
        // fails outright without this. String-keyed driverOptions are appended
        // to the DSN verbatim, hence '1' rather than a bool that would be
        // stringified on the way through.
        if (str_contains($driver, 'sqlsrv')) {
            $params['driverOptions']['TrustServerCertificate'] = '1';
        }

        return $params;
    }

    /**
     * Drop every mapped table, foreign keys notwithstanding.
     *
     * SchemaTool::dropSchema() ignores statements that fail, so on MySQL —
     * where dropping a table another table still references is an error — the
     * referenced tables survive the drop, and the next createSchema() fails
     * with "table already exists". The referential checks are suspended for
     * the duration rather than the drop order being guessed at.
     *
     * @param list<\Doctrine\ORM\Mapping\ClassMetadata<object>> $classes
     */
    private static function dropSchema(SchemaTool $schemaTool, array $classes, Connection $connection): void
    {
        $platform = $connection->getDatabasePlatform();

        $toggle = match (true) {
            $platform instanceof AbstractMySQLPlatform => ['SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'],
            $platform instanceof SQLitePlatform => ['PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = ON'],
            // PostgreSQL and SQL Server drop their constraints along with the
            // tables, so SchemaTool's own ordering is enough there.
            default => null,
        };

        if ($toggle !== null) {
            $connection->executeStatement($toggle[0]);
        }

        $schemaTool->dropSchema($classes);

        if ($toggle !== null) {
            $connection->executeStatement($toggle[1]);
        }
    }

    /**
     * The engine the suite is currently running against, for tests that must
     * assert something engine-specific.
     */
    public static function driver(): string
    {
        return getenv('DB_DRIVER') ?: 'pdo_sqlite';
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
        self::dropSchema($schemaTool, $classes, $connection);
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
