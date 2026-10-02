<?php

namespace App\Services\Nobitex;

use Illuminate\Support\Facades\Log;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

class NobitexWebSocketClient
{
    private Client $client;
    private array $response;
    private string $host = 'ws.nobitex.ir';
    private string $path = '/connection/websocket';
    private int $port = 443;

    public function connect(): void
    {
        $this->client = new Client(
            $this->host,
            $this->port,
            true,
        );

        $this->client->set([
            'timeout' => 10,
            'websocket_mask' => true,
            'websocket_compression' => false,
        ]);

        if (!$this->client->upgrade($this->path)) {
            throw new \RuntimeException(
                "WebSocket upgrade failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }

        $payload = ['connect' => (object)[], 'id' => 1];

        $this->send($payload);

        $frame = $this->receive();

        if ($frame === null) {
            throw new \RuntimeException('Connection response not received.');
        }

        Log::info('Nobitex WebSocket connected', [
            'response' => $frame,
        ]);
    }

    private function receive(): ?string
    {
        $frame = $this->client->recv(30);

        if ($frame === false) {
            return null;
        }

        if ($frame instanceof Frame) {
            return $frame->data;
        }

        if (is_string($frame)) {
            return $frame;
        }

        Log::warning('Unknown WebSocket frame type', [
            'type' => get_debug_type($frame),
        ]);

        return null;
    }

    private function send(array $payload): void
    {
        $message = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        if (!$this->client->push($message)) {
            throw new \RuntimeException(
                "WebSocket push failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }
    }

    public function subscribe(string $channel): void
    {
        $payload = [
            'id' => 2,
            'subscribe' => [
                'channel' => $channel,
            ],
        ];

        $this->send($payload);

        $frame = $this->receive();

        if ($frame === null) {
            throw new \RuntimeException(
                'Subscribe response not received.'
            );
        }

        Log::info('Nobitex channel subscribed', [
            'channel' => $channel,
            'response' => $frame,
        ]);
    }

    public function listen(): void
    {
        while (true) {

            echo "Waiting for message...\n";

            $raw = $this->receive();

            if ($raw === null) {
                echo "Receive failed.\n";

                throw new \RuntimeException(
                    "WebSocket receive failed: {$this->client->errCode} - {$this->client->errMsg}"
                );
            }

            echo "Received: {$raw}\n";

            $this->handleResponse($raw);
        }
    }

    public function reconnect(): void
    {
        // اتصال مجدد در صورت قطع شدن
    }

    private function handleResponse(string $message): void
    {
        $response = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Invalid JSON\n";
            return;
        }

        if ($response === []) {
            echo "Heartbeat received -> Pong\n";

            $this->handleHeartbeat();

            return;
        }

        if (isset($response['push']['pub']['data'])) {

            $data = $response['push']['pub']['data'];

            echo "OrderBook update\n";

            echo "Last price: "
                . ($data['lastTradePrice'] ?? 'N/A')
                . PHP_EOL;

            if (!empty($data['bids'])) {
                echo "Best bid: "
                    . $data['bids'][0][0]
                    . " / "
                    . $data['bids'][0][1]
                    . PHP_EOL;
            }

            if (!empty($data['asks'])) {
                echo "Best ask: "
                    . $data['asks'][0][0]
                    . " / "
                    . $data['asks'][0][1]
                    . PHP_EOL;
            }

            return;
        }

        echo "Other message:\n";
        var_dump($response);

    }

    private function handleHeartbeat(): void
    {
        if (!$this->client->push('{}')) {
            throw new \RuntimeException(
                "Pong failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }

        Log::debug('Nobitex heartbeat pong sent');
    }
    public function close(): void
    {
        $this->client->close();
    }
}
