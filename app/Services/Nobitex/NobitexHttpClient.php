<?php

namespace App\Services\Nobitex;

use Illuminate\Support\Facades\Http;

class NobitexHttpClient
{
    private string $baseUrl = 'https://apiv2.nobitex.ir';

    public function getOrderBook(string $symbol): array
    {
        $response = Http::timeout(10)
            ->acceptJson()
            ->withHeaders([
                'User-Agent' => 'TraderBot/NobitexBot-1.0.0',
            ])
            ->get(
                "{$this->baseUrl}/v3/orderbook/{$symbol}"
            );

        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            throw new \RuntimeException(
                'Invalid orderbook response.'
            );
        }

        if (($data['status'] ?? null) !== 'ok') {
            throw new \RuntimeException(
                'Nobitex orderbook request failed.'
            );
        }

        return $data;
    }
}
