<?php

namespace Database\Seeders;

use App\Models\MarketPair;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MarketPairSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $marketPairs = [
            // جفت‌ارزهای پایه‌ ریالی (IRT)
            [
                'symbol' => 'BTCIRT',
                'src_currency' => 'btc',
                'dst_currency' => 'irt',
                'min_amount' => 0.00010000,
                'tick_size' => 1000.00000000, // ۱,۰۰۰ ریال
                'is_active' => true,
            ],
            [
                'symbol' => 'USDTIRT',
                'src_currency' => 'usdt',
                'dst_currency' => 'irt',
                'min_amount' => 1.00000000,
                'tick_size' => 100.00000000, // ۱۰۰ ریال
                'is_active' => true,
            ],
            [
                'symbol' => 'ETHIRT',
                'src_currency' => 'eth',
                'dst_currency' => 'irt',
                'min_amount' => 0.00200000,
                'tick_size' => 1000.00000000,
                'is_active' => true,
            ],
            [
                'symbol' => 'TRXIRT',
                'src_currency' => 'trx',
                'dst_currency' => 'irt',
                'min_amount' => 10.00000000,
                'tick_size' => 10.00000000,
                'is_active' => true,
            ],
            [
                'symbol' => 'TONIRT',
                'src_currency' => 'ton',
                'dst_currency' => 'irt',
                'min_amount' => 0.50000000,
                'tick_size' => 100.00000000,
                'is_active' => true,
            ],

            // جفت‌ارزهای پایه تتری (USDT)
            [
                'symbol' => 'BTCUSDT',
                'src_currency' => 'btc',
                'dst_currency' => 'usdt',
                'min_amount' => 0.00010000,
                'tick_size' => 0.01000000,
                'is_active' => true,
            ],
            [
                'symbol' => 'ETHUSDT',
                'src_currency' => 'eth',
                'dst_currency' => 'usdt',
                'min_amount' => 0.00200000,
                'tick_size' => 0.01000000,
                'is_active' => true,
            ],
            [
                'symbol' => 'TONUSDT',
                'src_currency' => 'ton',
                'dst_currency' => 'usdt',
                'min_amount' => 0.50000000,
                'tick_size' => 0.00100000,
                'is_active' => true,
            ],
        ];

        foreach ($marketPairs as $pair) {
            MarketPair::updateOrCreate(
                ['symbol' => $pair['symbol']],
                $pair
            );
        }
    }
}
