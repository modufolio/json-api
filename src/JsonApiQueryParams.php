<?php

declare(strict_types = 1);

namespace Modufolio\JsonApi;

/**
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
class JsonApiQueryParams
{
    /**
     * @param list<string>         $fields
     * @param array<string, mixed> $filter
     * @param list<string>         $include
     * @param list<string>         $sort
     * @param array<string, mixed> $page
     * @param list<string>         $group
     * @param array<string, mixed> $having
     */
    public function __construct(
        public array $fields = [],
        public array $filter = [],
        public array $include = [],
        public array $sort = [],
        public array $page = ['number' => 1, 'size' => 10],
        public array $group = [],
        public array $having = ['query' => '', 'bindings' => []],
        public ?string $id = null
    ) {
    }
}
