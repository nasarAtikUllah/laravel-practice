<?php

namespace App\Support;

final class Money
{
    public static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function currency(): string
    {
        return (string) config('shop.currency', 'BDT');
    }
}
