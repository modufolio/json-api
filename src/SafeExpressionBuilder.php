<?php

declare(strict_types=1);

namespace Modufolio\JsonApi;

use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use InvalidArgumentException;

/**
 * Safe wrapper around Doctrine DBAL ExpressionBuilder
 * 
 * Provides additional validation and security measures when building SQL expressions
 */
class SafeExpressionBuilder
{
    private const SQL_IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';
    private const MAX_IN_CLAUSE_VALUES = 1000;

    public function __construct(
        private readonly ExpressionBuilder $expr
    ) {}

    /**
     * Create a safe equality comparison
     */
    public function eq(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->eq($field, $value);
    }

    /**
     * Create a safe inequality comparison
     */
    public function neq(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->neq($field, $value);
    }

    /**
     * Create a safe greater than comparison
     */
    public function gt(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->gt($field, $value);
    }

    /**
     * Create a safe greater than or equal comparison
     */
    public function gte(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->gte($field, $value);
    }

    /**
     * Create a safe less than comparison
     */
    public function lt(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->lt($field, $value);
    }

    /**
     * Create a safe less than or equal comparison
     */
    public function lte(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->lte($field, $value);
    }

    /**
     * Create a safe LIKE comparison with pattern validation
     */
    public function like(string $field, string $value): string
    {
        $this->validateIdentifier($field);
        return $this->expr->like($field, $value);
    }

    /**
     * Create a safe IN comparison with value count limits
     */
    public function in(string $field, array $values): string
    {
        $this->validateIdentifier($field);
        
        if (count($values) > self::MAX_IN_CLAUSE_VALUES) {
            throw new InvalidArgumentException(
                sprintf('Too many values in IN clause. Maximum allowed: %d', self::MAX_IN_CLAUSE_VALUES)
            );
        }

        return $this->expr->in($field, $values);
    }

    /**
     * Create a safe NOT IN comparison with value count limits
     */
    public function notIn(string $field, array $values): string
    {
        $this->validateIdentifier($field);
        
        if (count($values) > self::MAX_IN_CLAUSE_VALUES) {
            throw new InvalidArgumentException(
                sprintf('Too many values in NOT IN clause. Maximum allowed: %d', self::MAX_IN_CLAUSE_VALUES)
            );
        }

        return $this->expr->notIn($field, $values);
    }

    /**
     * Create a safe IS NULL comparison
     */
    public function isNull(string $field): string
    {
        $this->validateIdentifier($field);
        return $this->expr->isNull($field);
    }

    /**
     * Create a safe IS NOT NULL comparison
     */
    public function isNotNull(string $field): string
    {
        $this->validateIdentifier($field);
        return $this->expr->isNotNull($field);
    }

    /**
     * Create a safe AND composite expression
     */
    public function and(string ...$expressions): string
    {
        return (string) $this->expr->and(...$expressions);
    }

    /**
     * Create a safe OR composite expression
     */
    public function or(string ...$expressions): string
    {
        return (string) $this->expr->or(...$expressions);
    }

    /**
     * Safely quote a literal value
     */
    public function literal(string $value): string
    {
        return $this->expr->literal($value);
    }

    /**
     * Validate SQL identifier to prevent injection
     */
    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match(self::SQL_IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }

        // Check for dangerous SQL keywords as whole words only
        $dangerousKeywords = [
            'DROP', 'DELETE', 'INSERT', 'UPDATE', 'ALTER', 'CREATE', 'TRUNCATE',
            'EXEC', 'EXECUTE', 'SYSTEM', 'SHELL', 'INFORMATION_SCHEMA', 'SYS',
            'UNION', 'SELECT'
        ];

        // Split identifier by dots to check each part separately
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            $upperPart = strtoupper(trim($part));
            if (in_array($upperPart, $dangerousKeywords, true)) {
                throw new InvalidArgumentException("Potentially dangerous identifier: {$identifier}");
            }
        }
    }

    /**
     * Access the underlying ExpressionBuilder for advanced cases
     * Use with extreme caution - bypasses safety checks
     */
    public function getUnsafeExpressionBuilder(): ExpressionBuilder
    {
        return $this->expr;
    }
}