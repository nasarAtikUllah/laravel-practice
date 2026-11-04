<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Initial = 'initial';
    case Restock = 'restock';
    case Correction = 'correction';
    case Damage = 'damage';
    case Sale = 'sale';
    case Cancellation = 'cancellation';
    case Return = 'return';
}
