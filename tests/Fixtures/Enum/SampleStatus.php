<?php

declare(strict_types=1);

namespace Modufolio\JsonApi\Tests\Fixtures\Enum;

enum SampleStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
