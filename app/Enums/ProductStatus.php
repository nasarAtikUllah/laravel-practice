<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Upcoming = 'upcoming';
    case Archived = 'archived';

    /** @return array<int, string> the statuses the public website shows */
    public static function visibleValues(): array
    {
        return [self::Published->value, self::Upcoming->value];
    }
}
