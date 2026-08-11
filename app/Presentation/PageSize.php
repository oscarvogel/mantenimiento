<?php

declare(strict_types=1);

namespace App\Presentation;

final class PageSize
{
    public const DEFAULT = 10;
    public const ALLOWED = [5, 10, 25];

    public static function normalize(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && in_array($value, self::ALLOWED, true) ? $value : self::DEFAULT;
    }
}
