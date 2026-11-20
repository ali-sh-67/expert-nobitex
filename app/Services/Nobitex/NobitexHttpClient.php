<?php

namespace App\Services\Nobitex;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;
class NobitexHttpClient
{
    private string $baseUrl = 'apiv2.nobitex.ir';

    public function getOrderBook(string $symbol): array
    {
        // اگر خارج از Coroutine هستیم، درخواست را داخل آن اجرا کن.
        if (Coroutine::getCid() < 0) {
            $result = null;

            run(function () use ($symbol, &$result) {
                $result = $this->getOrderBook($symbol);
            });

            if (!is_array($result)) {
                throw new \RuntimeException(
                    'Failed to fetch order book inside coroutine.'
                );
            }

            return $result;
        }

        $client = new Client($this->baseUrl, 443, true);

        try {
            $client->set([
                'timeout' => 10,
                'ssl_verify_peer' => true,
                'ssl_allow_self_signed' => false,
            ]);

            $client->setHeaders([
                'Host' => $this->baseUrl,
                'User-Agent' => 'TraderBot/NobitexBot-1.0.0',
                'Accept' => 'application/json',
            ]);

            $client->get("/v3/orderbook/{$symbol}");

            if ($client->statusCode !== 200) {
                throw new \RuntimeException(
                    "Nobitex HTTP error: {$client->statusCode}"
                );
            }

            $data = json_decode(
                $client->body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
                throw new \RuntimeException(
                    'Invalid Nobitex orderbook response.'
                );
            }

            return $data;
        } finally {
            $client->close();
        }
    }
}
