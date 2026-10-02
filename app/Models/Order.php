<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    protected $fillable = [
        'market_pair_id',
        'strategy_id',
        'nobitex_order_id',
        'type',
        'execution_type',
        'price',
        'amount',
        'filled_amount',
        'stop_loss',
        'take_profit',
        'fee',
        'status',
        'error_message',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'amount' => 'decimal:8',
        'filled_amount' => 'decimal:8',
        'stop_loss' => 'decimal:8',
        'take_profit' => 'decimal:8',
        'fee' => 'decimal:8',
    ];

    public function marketPair(): BelongsTo
    {
        return $this->belongsTo(MarketPair::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
