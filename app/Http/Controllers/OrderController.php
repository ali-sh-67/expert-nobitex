<?php

namespace App\Http\Controllers;

use App\Services\Nobitex\NobitexApiService;

class OrderController extends Controller
{
    public function checkBalance(NobitexApiService $nobitex)
    {
        // دریافت موجودی کیف پول‌ها
        $wallets = $nobitex->getWallets();

        // ثبت یک سفارش خرید لیمیت
        $order = $nobitex->addOrder(
            type: 'buy',
            srcCurrency: 'btc',
            dstCurrency: 'irt',
            amount: 0.001,
            price: 5500000000 // قیمت به ریال
        );

        return response()->json($order);
    }
}
