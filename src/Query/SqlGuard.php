<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Query;

/**
 * Lexical checks on SQL fragments the builder cannot bind as parameters.
 *
 * Column names and HAVING conditions are spliced into the statement as text,
 * so they are the one place a request could smuggle SQL in. These checks are
 * a fence, not a parser: they refuse anything outside a conservative shape
 * rather than trying to recognise every attack.
 *
 * @internal Part of {@see \Modufolio\JsonApi\JsonApiQueryBuilder}'s implementation.
 */
final class SqlGuard
{
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Keywords a column name is never allowed to be, checked as whole words
     * per dot-separated part.
     */
    private const RESERVED_WORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'EXEC', 'EXECUTE', 'UNION', 'OR', 'AND', '--', '/*', '*/',
        'INFORMATION_SCHEMA', 'SYS', 'SYSTEM', 'DUAL',
    ];

    private const HAVING_ALLOWED_PATTERN = '/^[a-zA-Z0-9_\s\(\)\.,=<>!:\*]+$/';

    private const HAVING_FORBIDDEN_PATTERNS = [
        '/\b(DROP|DELETE|INSERT|UPDATE|ALTER|CREATE|TRUNCATE)\b/i',
        '/\b(EXEC|EXECUTE|SYSTEM|SHELL)\b/i',
        '/\b(INFORMATION_SCHEMA|SYS)\b/i',
        '/-{2,}/',          // line comments
        '/\/\*.*?\*\//',    // block comments
        '/\bUNION\b/i',
        '/\bOR\b.*\b1\s*=\s*1\b/i', // the classic tautology
        '/;/',              // statement terminators
    ];

    public static function isIdentifier(string $identifier): bool
    {
        return preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1;
    }

    /**
     * A column name is an identifier that is not also a reserved word.
     */
    public static function isSafeColumnName(string $identifier): bool
    {
        if (!self::isIdentifier($identifier)) {
            return false;
        }

        foreach (explode('.', $identifier) as $part) {
            if (in_array(strtoupper(trim($part)), self::RESERVED_WORDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A HAVING condition may hold aggregations, comparisons and parameter
     * placeholders — nothing that could open a second statement.
     */
    public static function isSafeHavingCondition(string $condition): bool
    {
        if (preg_match(self::HAVING_ALLOWED_PATTERN, $condition) !== 1) {
            return false;
        }

        foreach (self::HAVING_FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $condition)) {
                return false;
            }
        }

        return true;
    }
}
