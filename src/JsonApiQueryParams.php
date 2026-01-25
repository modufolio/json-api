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
