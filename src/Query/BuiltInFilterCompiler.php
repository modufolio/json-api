<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Modufolio\JsonApi\SafeExpressionBuilder;

/**
 * The filter operators the builder understands on its own, used when no
 * {@see \Modufolio\JsonApi\Filter\FilterRegistry} claims the resource.
 *
 * A bare value is equality; an operator map supports `null`, `not_null`,
 * `not`/`neq`, `gt`, `gte`, `lt`, `lte`, `like` and `in`. Conditions are
 * added to the query builder directly, bindings are returned for the caller
 * to set alongside its other parameters.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class BuiltInFilterCompiler
{
    /** Nesting a filter value deeper than this is refused (DoS protection). */
    private const MAX_FILTER_DEPTH = 5;

    private const MAX_IN_VALUES = 1000;

    private const MAX_LIKE_LENGTH = 255;

    public function __construct(private readonly ResourceSchema $schema)
    {
    }

    /**
     * @param array<array-key, mixed> $filters      Field-keyed filter values.
     * @param int                     $paramOffset  Parameters already bound on the query; new names continue the sequence.
     *
     * @return array<string, mixed> The bindings for the conditions added.
     */
    public function apply(QueryBuilder $qb, string $alias, array $filters, int $paramOffset): array
    {
        $bindings = [];
        $expr = new SafeExpressionBuilder($qb->expr());

        foreach ($filters as $field => $value) {
            $column = $this->schema->columnName((string) $field);
            $this->applyOne($qb, $expr, "$alias.$column", $column, $value, $bindings, $paramOffset);
        }

        return $bindings;
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function applyOne(
        QueryBuilder $qb,
        SafeExpressionBuilder $expr,
        string $fullColumn,
        string $column,
        mixed $value,
        array &$bindings,
        int $paramOffset,
    ): void {
        if (!SqlGuard::isSafeColumnName($column)) {
            throw new InvalidArgumentException("Invalid column name: $column");
        }

        if ($this->depthOf($value) > self::MAX_FILTER_DEPTH) {
            throw new InvalidArgumentException('Filter structure too deep - maximum depth exceeded');
        }

        $bind = static function (mixed $v) use (&$bindings, $paramOffset): string {
            $param = 'p' . ($paramOffset + count($bindings));
            $bindings[$param] = $v;

            return ':' . $param;
        };

        if (!is_array($value)) {
            $qb->andWhere($expr->eq($fullColumn, $bind($value)));
            return;
        }

        if (array_key_exists('null', $value)) {
            $qb->andWhere($expr->isNull($fullColumn));
        } elseif (isset($value['not_null'])) {
            $qb->andWhere($expr->isNotNull($fullColumn));
        } elseif (isset($value['not']) || isset($value['neq'])) {
            $qb->andWhere($expr->neq($fullColumn, $bind($value['not'] ?? $value['neq'])));
        } elseif (isset($value['gt'])) {
            $qb->andWhere($expr->gt($fullColumn, $bind($value['gt'])));
        } elseif (isset($value['gte'])) {
            $qb->andWhere($expr->gte($fullColumn, $bind($value['gte'])));
        } elseif (isset($value['lt'])) {
            $qb->andWhere($expr->lt($fullColumn, $bind($value['lt'])));
        } elseif (isset($value['lte'])) {
            $qb->andWhere($expr->lte($fullColumn, $bind($value['lte'])));
        } elseif (isset($value['like'])) {
            $qb->andWhere($expr->like($fullColumn, $bind($this->likePattern($value['like']))));
        } elseif (isset($value['in'])) {
            if (empty($value['in'])) {
                $qb->andWhere('1 = 0');
            } else {
                if (count($value['in']) > self::MAX_IN_VALUES) {
                    throw new InvalidArgumentException('IN clause contains too many values - maximum 1000 allowed');
                }

                $placeholders = [];
                foreach ($value['in'] as $item) {
                    $placeholders[] = $bind($item);
                }
                $qb->andWhere($expr->in($fullColumn, $placeholders));
            }
        } else {
            throw new InvalidArgumentException("Unsupported filter operator for column: $column");
        }
    }

    private function likePattern(string $pattern): string
    {
        if (strlen($pattern) > self::MAX_LIKE_LENGTH) {
            throw new InvalidArgumentException('LIKE pattern too long - maximum 255 characters allowed');
        }

        return $pattern;
    }

    private function depthOf(mixed $value, int $currentDepth = 0): int
    {
        if (!is_array($value)) {
            return $currentDepth;
        }

        $maxDepth = $currentDepth;
        foreach ($value as $item) {
            if (is_array($item)) {
                $maxDepth = max($maxDepth, $this->depthOf($item, $currentDepth + 1));
            }
        }

        return $maxDepth;
    }
}
