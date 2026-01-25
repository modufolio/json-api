<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Filter;

/**
 * Search strategies for SearchFilter
 */
enum SearchStrategy: string
{
    case PARTIAL = 'partial';  // LIKE %value% (contains)
    case EXACT = 'exact';      // = value (exact match)
    case START = 'start';      // LIKE value% (starts with)
    case END = 'end';          // LIKE %value (ends with)
}
