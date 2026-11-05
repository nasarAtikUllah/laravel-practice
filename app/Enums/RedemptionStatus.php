<?php

namespace App\Enums;

enum RedemptionStatus: string
{
    case Reserved = 'reserved';
    case Redeemed = 'redeemed';
    case Released = 'released';
    case Expired = 'expired';

    /** @return array<int, string> the statuses that count as a use of the coupon */
    public static function counting(): array
    {
        return [self::Reserved->value, self::Redeemed->value];
    }
}
