<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

final class InventoryDateTime
{
    public const OPERATING_TIMEZONE = 'America/Hermosillo';

    public static function toUtc(DateTimeInterface|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc();
        }

        return Carbon::parse($value, self::OPERATING_TIMEZONE)->utc();
    }
}
