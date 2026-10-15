<?php

namespace App\Services\Nobitex;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

class NobitexWebSocketClient
{
    private ?Client $client = null;
    private string $host = 'ws.nobitex.ir';
    private string $path = '/connection/websocket';
    private int $port = 443;

    public function __construct(private readonly OrderBookHandler $orderBookHandler)
    {
    }

    public function run(string $channel): void
    {
        $attempt = 0;

        while (true) {

            try {

                echo "Connecting...\n";

                $this->connect();

                echo "Connected.\n";

                $this->subscribe($channel);

                echo "Subscribed.\n";

                $attempt = 0;

                echo "Listening...\n";

                $this->listen();

                echo "Connection lost.\n";

            } catch (Throwable $e) {

                Log::error('Nobitex WebSocket error', [
                    'message' => $e->getMessage(),
                ]);

                echo "Error: {$e->getMessage()}\n";
            }

            $this->orderBookHandler->invalidateConnection();

            $this->close();

            $attempt++;

            $delay = min(
                30,
                2 ** min($attempt, 5)
            );

            echo "Reconnecting in $delay seconds...\n";

            Coroutine::sleep($delay);
        }
    }

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
            throw new RuntimeException(
                "WebSocket upgrade failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }

        $payload = ['connect' => (object)[], 'id' => 1];

        $this->send($payload);

        $frame = $this->receive();

        if ($frame === null) {
            throw new RuntimeException('Connection response not received.');
        }

        Log::info('Nobitex WebSocket connected', [
            'response' => $frame,
        ]);
    }

    private function receive(): ?string
    {
//        $frame = $this->client->recv(30);
        $startedAt = microtime(true);
        $frame = $this->client->recv(30);
        $elapsed = round(microtime(true) - $startedAt, 3);

        if ($frame === false) {
            echo sprintf(
                "[RECV FALSE] elapsed=%.3fs errCode=%s errMsg=%s connected=%s\n",
                $elapsed,
                $this->client->errCode,
                $this->client->errMsg,
                var_export($this->client->connected, true),
            );
        } elseif ($frame === '') {
            echo sprintf(
                "[RECV EMPTY] elapsed=%.3fs connected=%s\n",
                $elapsed,
                var_export($this->client->connected, true),
            );
        } elseif ($frame instanceof Frame) {
            echo sprintf(
                "[RECV FRAME] elapsed=%.3fs bytes=%d\n",
                $elapsed,
                strlen($frame->data),
            );
        }

        if ($frame instanceof Frame) {
            return $frame->data;
        }

        // Swoole 6.1+: رشته خالی یعنی سرور اتصال را بسته است.
        if ($frame === '') {
            return null;
        }

        if ($frame === false) {
            Log::warning('Nobitex WebSocket receive failed.', [
                'error_code' => $this->client->errCode,
                'error_message' => $this->client->errMsg,
                'connected' => $this->client->connected,
            ]);

            return null;
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
            throw new RuntimeException(
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
            throw new RuntimeException(
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
            $raw = $this->receive();
            if ($raw === null) {

                Log::warning('Nobitex WebSocket connection lost', [
                    'error_code' => $this->client->errCode,
                    'error_message' => $this->client->errMsg,
                ]);

                return;
            }
            $this->handleResponse($raw);
        }
    }

    private function handleResponse(string $message): void
    {
        $response = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        if ($response === []) {
            echo "Heartbeat received -> Pong\n";

            $this->handleHeartbeat();

            return;
        }

        if (isset($response['push']['pub']['data'])) {
            $data = $response['push']['pub']['data'];
//
//            dump("----- WS ORDERBOOK ali-----");
//
//            dump($response);

            $this->orderBookHandler->handle($data);

            return;
        }

        echo "Other message:\n";
        var_dump($response);
    }

    private function handleHeartbeat(): void
    {
        if (!$this->client->push('{}')) {
            throw new RuntimeException(
                "Pong failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }

        Log::debug('Nobitex heartbeat pong sent');
    }

    public function close(): void
    {
        if ($this->client === null) {
            return;
        }

        $client = $this->client;
        $this->client = null;

        try {
            $client->close();
        } catch (Throwable $e) {
            Log::warning('Error while closing Nobitex WebSocket', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
