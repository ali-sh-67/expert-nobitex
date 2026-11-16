<?php


return [
    'market' => [
        'max_order_book_age_ms' => (int)env(
            'NOBITEX_MAX_ORDER_BOOK_AGE_MS',
            1500
        ),
    ],

    'strategies' => [
        'mode' => env('NOBITEX_STRATEGY_MODE', 'single'),

        'active' => array_values(array_filter(
            array_map(
                'trim',
                explode(',', env(
                    'NOBITEX_ACTIVE_STRATEGIES',
                    'simple_orderbook'
                ))
            )
        )),
    ],
    'markets' => [
        'symbols' => array_values(array_filter(
            array_map(
                'trim',
                explode(',', env(
                    'NOBITEX_MARKETS',
                    'BTCIRT,ETHIRT,USDTIRT'
                ))
            )
        )),
    ],
];
