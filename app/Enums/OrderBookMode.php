<?php

namespace App\Enums;

enum OrderBookMode: string
{
    case SNAPSHOT = 'snapshot';
    case DELTA = 'delta';
}
