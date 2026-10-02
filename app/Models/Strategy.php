<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Strategy extends Model
{
    protected $fillable = [
        'market_pair_id',
        'name',
        'type',
        'config',
        'allocated_capital',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'allocated_capital' => 'decimal:4',
    ];

    public function marketPair(): BelongsTo
    {
        return $this->belongsTo(MarketPair::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
