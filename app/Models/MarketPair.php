<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketPair extends Model
{
    protected $fillable = [
        'symbol',
        'src_currency',
        'dst_currency',
        'min_amount',
        'tick_size',
        'is_active',
        'last_price',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_amount' => 'decimal:8',
        'tick_size' => 'decimal:8',
        'last_price'=>'decimal:8',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }
}
