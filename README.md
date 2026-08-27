# Modufolio JSON:API

[![License: MIT](https://img.shields.io/badge/License-MIT-brightgreen.svg?style=flat-square)](https://opensource.org/licenses/MIT) [![codecov](https://img.shields.io/codecov/c/github/modufolio/json-api?token=AK3DIZQQJB&style=flat-square)](https://codecov.io/gh/modufolio/json-api)

A PHP implementation of the [JSON:API](https://jsonapi.org/) specification for Doctrine ORM.

## Features

- Full JSON:API 1.0 specification compliance
- Doctrine ORM integration
- Flexible filtering system with custom filter support
- Sorting, pagination, and sparse fieldsets
- Relationship handling (to-one and to-many)
- Content negotiation
- Validation support via Symfony Validator
- PSR-7 HTTP message interfaces

## Installation

```bash
composer require modufolio/json-api
```

## Requirements

- PHP 8.2 or higher
- Doctrine ORM 3.0+
- PSR-7 HTTP Message implementation
- PSR-17 HTTP Factory implementation

Tested against SQLite, MySQL 8.4, PostgreSQL 16 and SQL Server 2022.

## Testing

The suite runs on SQLite in memory by default, so a fresh checkout needs no
setup:

```bash
composer test
```

The engines disagree in ways that change which rows a query returns — where
NULLs sort, whether `LIKE` folds case, whether a partially-grouped SELECT is
even legal — so the same suite runs against each of them. `docker compose up -d`
brings the databases up locally:

```bash
DB_DRIVER=pdo_pgsql  DB_PORT=5433 DB_USER=postgres DB_PASSWORD=secret vendor/bin/phpunit
DB_DRIVER=pdo_mysql  DB_PORT=3307 DB_USER=root     DB_PASSWORD=secret vendor/bin/phpunit
DB_DRIVER=pdo_sqlsrv DB_PORT=1434 DB_USER=sa       DB_PASSWORD='Secret_1234' vendor/bin/phpunit
```

CI runs the full matrix on every push.

## Documentation

- [Installation and Setup](docs/installation.md)
- [Basic Usage](docs/basic-usage.md)
- [Configuration](docs/configuration.md)
- [Filtering](docs/filtering.md)
- [Query Builder](docs/query-builder.md)
- [API Reference](docs/reference/index.md)
- [Errors](docs/reference/errors.md)


## License

MIT


