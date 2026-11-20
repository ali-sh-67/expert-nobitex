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
    private int $requestId = 2;

    private array $pendingSubscriptions = [];


    public function __construct(
        private readonly MarketDataManager       $marketDataManager,
        private readonly MarketDataMessageRouter $messageRouter,
        private readonly SubscriptionManager $subscriptionManager,
    )
    {
    }

    public function run(array $channels): void
    {
        $symbols = [];

        foreach ($channels as $channel) {
            if (preg_match('/^public:orderbook-([A-Z0-9]+)$/', $channel, $matches)) {
                $symbols[] = $matches[1];
            }
        }

        $symbols = array_values(array_unique($symbols));

        if (empty($symbols)) {
            throw new RuntimeException('No valid market channels provided.');
        }

        $this->marketDataManager->registerMarkets($symbols);


        $attempt = 0;

        while (true) {

            try {

                echo "Connecting...\n";

                $this->requestId = 2;
                $this->pendingSubscriptions = [];

                $this->connect();
                echo "Connected.\n";

                foreach ($channels as $channel) {
                    $this->subscribe($channel);
                    echo "Subscribed: {$channel}\n";
                }

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

            $this->marketDataManager->invalidateAll();

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
        $recoveryPosition = $this->subscriptionManager
            ->getRecoveryPosition($channel);

        $subscribePayload = [
            'channel' => $channel,
        ];

        if ($recoveryPosition !== null) {
            $subscribePayload['recover'] = true;
            $subscribePayload['epoch'] = $recoveryPosition['epoch'];
            $subscribePayload['offset'] = $recoveryPosition['offset'];
        }

        $requestId = $this->requestId++;

        $this->pendingSubscriptions[$requestId] = [
            'channel' => $channel,
            'recovery_requested' => $recoveryPosition !== null,
        ];

        try {
            $this->send([
                'id' => $requestId,
                'subscribe' => $subscribePayload,
            ]);
        } catch (Throwable $e) {
            unset($this->pendingSubscriptions[$requestId]);
            throw $e;
        }

        Log::info('Nobitex subscribe request sent', [
            'channel' => $channel,
            'request_id' => $requestId,
            'recovery_requested' => $recoveryPosition !== null,
            'recovery_position' => $recoveryPosition,
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
        $lines = preg_split('/\r\n|\n|\r/', trim($message));

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $response = json_decode($line, true);

            if (
                json_last_error() !== JSON_ERROR_NONE ||
                !is_array($response)
            ) {
                Log::warning('Invalid WebSocket JSON response', [
                    'message' => $line,
                ]);

                continue;
            }

            // Heartbeat
            if ($response === []) {
                $this->handleHeartbeat();
                continue;
            }

            $requestId = isset($response['id'])
                ? (int) $response['id']
                : 0;

            // پاسخ درخواست Subscribe
            if (
                $requestId > 0 &&
                isset($this->pendingSubscriptions[$requestId])
            ) {
                $this->handleSubscribeReply($requestId, $response);
                continue;
            }

            // Publication عادی
            if (isset($response['push']['pub'])) {
                $this->handlePublication($response);
                continue;
            }

            // پاسخ اتصال
            if (isset($response['connect'])) {
                Log::info('Nobitex WebSocket handshake response received');
                continue;
            }

            // خطای سایر درخواست‌ها
            if (isset($response['error'])) {
                Log::warning('Nobitex WebSocket command error', [
                    'response' => $response,
                ]);

                continue;
            }

            Log::debug('Other Nobitex WebSocket message', [
                'response' => $response,
            ]);
        }
    }

    private function handleSubscribeReply(
        int $requestId,
        array $response
    ): void {
        $pending = $this->pendingSubscriptions[$requestId];

        unset($this->pendingSubscriptions[$requestId]);

        $channel = $pending['channel'];

        if (isset($response['error'])) {
            $this->subscriptionManager->invalidate($channel);

            throw new RuntimeException(
                "Subscribe failed for {$channel}: " .
                json_encode($response['error'])
            );
        }

        $result = $response['subscribe'] ?? null;

        if (!is_array($result)) {
            throw new RuntimeException(
                "Invalid Subscribe response for {$channel}."
            );
        }

        // اگر سرور این فیلد را برگردانده باشد، مقدار سرور ملاک است.
        // در غیر این صورت از درخواست ارسالی استفاده می‌کنیم.
        $wasRecovering = array_key_exists('was_recovering', $result)
            ? (bool) $result['was_recovering']
            : $pending['recovery_requested'];

        $recovered = (bool) ($result['recovered'] ?? false);

        $this->subscriptionManager->updateSubscription(
            $channel,
            isset($result['epoch']) && $result['epoch'] !== ''
                ? (string) $result['epoch']
                : null,
            isset($result['offset']) && is_numeric($result['offset'])
                ? (int) $result['offset']
                : 0,
            (bool) ($result['recoverable'] ?? false),
            (bool) ($result['positioned'] ?? false),
            $wasRecovering,
            $recovered
        );

        Log::info('Nobitex subscription established', [
            'channel' => $channel,
            'was_recovering' => $wasRecovering,
            'recovered' => $recovered,
            'epoch' => $result['epoch'] ?? null,
            'offset' => $result['offset'] ?? null,
            'recoverable' => $result['recoverable'] ?? false,
            'positioned' => $result['positioned'] ?? false,
        ]);

        if ($wasRecovering && !$recovered) {
            Log::warning('Nobitex subscription recovery failed', [
                'channel' => $channel,
            ]);
        }

        // پردازش Publicationهای داخل پاسخ Subscribe
        $publications = $result['publications'] ?? [];

        foreach ($publications as $publication) {
            if (!is_array($publication)) {
                continue;
            }

            $this->handlePublication([
                'push' => [
                    'channel' => $channel,
                    'pub' => $publication,
                ],
            ]);
        }
    }

    private function handlePublication(array $message): void
    {
        $push = $message['push'] ?? [];

        $channel = $push['channel'] ?? null;
        $publication = $push['pub'] ?? null;

        if (
            !is_string($channel) ||
            !is_array($publication)
        ) {
            Log::warning('Invalid Nobitex publication', [
                'message' => $message,
            ]);

            return;
        }

        Log::debug('Nobitex orderbook update metadata', [
            'channel' => $channel,
            'offset' => $publication['offset'] ?? null,
            'lastUpdate' => $publication['data']['lastUpdate'] ?? null,
        ]);

        // پیام عادی و بازیابی‌شده از یک مسیر عبور می‌کنند.
        $processed = $this->messageRouter->route($message);

        // فعلاً Router فقط bool برمی‌گرداند.
        // در مرحله بعد نتیجه APPLIED / STALE / INVALID را دقیق می‌کنیم.
        if (
            $processed === true &&
            isset($publication['offset']) &&
            is_numeric($publication['offset'])
        ) {
            $this->subscriptionManager->updateOffset(
                $channel,
                (int) $publication['offset']
            );
        }
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
