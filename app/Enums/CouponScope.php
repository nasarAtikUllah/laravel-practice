<?php

namespace App\Enums;

enum CouponScope: string
{
    case All = 'all';
    case Brands = 'brands';
    case Products = 'products';
}
