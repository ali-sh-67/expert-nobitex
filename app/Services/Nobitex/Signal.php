<?php

namespace App\Services\Nobitex;

enum Signal: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';
    case HOLD = 'HOLD';
}
