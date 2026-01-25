<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Helpers;

/**
 * String helper class for JSON API
 */
class Str
{
    /**
     * The cache of snake-cased words.
     */
    protected static array $snakeCache = [];

    /**
     * The cache of camel-cased words.
     */
    protected static array $camelCache = [];

    /**
     * The cache of studly-cased words.
     */
    protected static array $studlyCache = [];

    /**
     * Convert a value to camel case.
     */
    public static function camel(string $value): string
    {
        return static::$camelCache[$value] ?? (static::$camelCache[$value] = lcfirst(static::studly($value)));
    }

    /**
     * Convert a value to studly caps case.
     */
    public static function studly(string $value): string
    {
        return static::$studlyCache[$value] ?? (static::$studlyCache[$value] = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value))));
    }

    /**
     * Convert a string to snake case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value . $delimiter;

        if (isset(static::$snakeCache[$key])) {
            return static::$snakeCache[$key];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', ucwords($value));
            $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
        }

        return static::$snakeCache[$key] = $value;
    }

    /**
     * Convert the given string to lower-case.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
