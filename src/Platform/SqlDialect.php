<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Platform;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;

/**
 * The few constructs that have no portable spelling.
 *
 * Almost everything this library emits is standard SQL, and DBAL abstracts the
 * mechanical differences on top of that — LIMIT versus OFFSET…FETCH, identifier
 * quoting, how a bound boolean is rendered. Branching per platform beyond that
 * multiplies the test matrix and rots, because the branch nobody runs locally
 * is the one that breaks.
 *
 * What earns a branch is a construct where the engines return *different rows*
 * for the same request and no single spelling works everywhere. NULL ordering
 * is the case: `NULLS LAST` is unsupported by MySQL, SQL Server and older
 * SQLite, so a deterministic order has to be expressed differently on each.
 *
 * Case-insensitive search is deliberately *not* here: `LOWER(col) LIKE
 * LOWER(:p)` works on every engine, so {@see \Modufolio\JsonApi\Filter\SearchFilter}
 * needs no dialect at all.
 */
final class SqlDialect
{
    private function __construct(
        private readonly AbstractPlatform $platform,
    ) {
    }

    public static function for(AbstractPlatform $platform): self
    {
        return new self($platform);
    }

    /**
     * An ORDER BY fragment that places NULLs last, whatever the direction.
     *
     * Left to the engine, the same `sort=score` returns a different first page
     * everywhere: PostgreSQL puts NULLs last ascending and first descending,
     * MySQL and SQLite the other way round. "Missing values at the end" is the
     * answer a client can reason about, so it is applied in both directions
     * rather than inheriting each engine's default.
     *
     * @return list<string> One or more ORDER BY expressions, in order
     */
    public function orderByNullsLast(string $expression, string $direction): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        if ($this->platform instanceof PostgreSQLPlatform) {
            return ["$expression $direction NULLS LAST"];
        }

        if ($this->platform instanceof SQLServerPlatform) {
            // No NULLS clause, and a bare boolean is not a sortable expression
            // there, so the nullness is ranked explicitly.
            return ["CASE WHEN $expression IS NULL THEN 1 ELSE 0 END ASC", "$expression $direction"];
        }

        // MySQL, MariaDB and SQLite: a comparison yields 0 or 1, and sorting on
        // it ascending puts the non-null rows first.
        return ["$expression IS NULL ASC", "$expression $direction"];
    }
}
