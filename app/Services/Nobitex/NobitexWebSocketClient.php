<?php

namespace App\Services\Nobitex;

use App\Enums\OrderBookMode;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;
use UnexpectedValueException;

class NobitexWebSocketClient
{
    private ?Client $client = null;

    private string $host = 'ws.nobitex.ir';

    private string $path = '/connection/websocket';

    private int $port = 443;

    private int $requestId = 2;

    private array $pendingSubscriptions = [];

    public function __construct(
        private readonly MarketDataManager $marketDataManager,
        private readonly MarketDataMessageRouter $messageRouter,
        private readonly SubscriptionManager $subscriptionManager,
    ) {}

    protected function shouldResetReconnectAttempts(
        ?float $sessionStartedAt,
        float $now
    ): bool {
        return $sessionStartedAt !== null
            && ($now - $sessionStartedAt) >= 60;
    }

    protected function calculateReconnectDelay(int $attempt): int
    {
        return min(30, 2 ** min($attempt, 5));
    }

    public function run(array $channels): void
    {
        if (empty($channels)) {
            throw new RuntimeException(
                'No valid market channels provided.'
            );
        }

        $validatedChannels = [];

        foreach ($channels as $channel) {
            if (
                ! is_string($channel) ||
                preg_match(
                    '/^public:orderbook-([A-Z0-9]+)$/D',
                    $channel,
                    $matches
                ) !== 1
            ) {
                throw new RuntimeException(
                    'Invalid market channel provided.'
                );
            }

            $symbol = $matches[1];

            if ($this->marketDataManager->getHandler($symbol) === null) {
                throw new RuntimeException(
                    "Market not registered: {$symbol}"
                );
            }

            $validatedChannels[$channel] = $channel;
        }

        $channels = array_values($validatedChannels);

        $attempt = 0;

        while (true) {
            $sessionStartedAt = null;
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
                $sessionStartedAt = microtime(true);
                echo "Listening...\n";

                $this->listen();

                echo "Connection lost.\n";

            } catch (Throwable $e) {

                Log::error('Nobitex WebSocket error', [
                    'message' => $e->getMessage(),
                ]);

                echo "Error: {$e->getMessage()}\n";
            }
            // تشخیص پایداری
            if ($this->shouldResetReconnectAttempts(
                $sessionStartedAt,
                microtime(true)
            )) {
                $attempt = 0;
            }

            $this->marketDataManager->suspendAll();

            $this->close();

            $attempt++;

            $delay = $this->calculateReconnectDelay($attempt);

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

        if (! $this->client->upgrade($this->path)) {
            throw new RuntimeException(
                "WebSocket upgrade failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }

        $payload = ['connect' => (object) [], 'id' => 1];

        $this->send($payload);

        $frame = $this->receive();

        if ($frame === false || $frame === null) {
            throw new RuntimeException(
                'WebSocket handshake response was not received.'
            );
        }

        $response = $this->validateHandshakeResponse($frame);

        Log::info('Nobitex WebSocket connected', [
            'response' => $response,
        ]);
    }

    protected function validateHandshakeResponse(string $frame): array
    {
        try {
            $response = json_decode(
                $frame,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new RuntimeException(
                'Invalid WebSocket handshake JSON response.',
                0,
                $e
            );
        }

        if (
            ! is_array($response) ||
            ($response['id'] ?? null) !== 1 ||
            ! is_array($response['connect'] ?? null) ||
            ! is_string($response['connect']['client'] ?? null) ||
            $response['connect']['client'] === '' ||
            isset($response['error'])
        ) {
            throw new RuntimeException(
                'Invalid or rejected WebSocket handshake response.'
            );
        }

        return $response;
    }

    private function receive(): string|false|null
    {
        $frame = $this->client->recv(30);

        if ($frame instanceof Frame) {
            return $frame->data;
        }

        // اتصال توسط سرور بسته شده است.
        if ($frame === '') {
            return null;
        }

        // Timeout یا خطای دریافت
        if ($frame === false) {
            $errorCode = $this->client->errCode;

            if ($errorCode === SOCKET_ETIMEDOUT) {
                return false;
            }

            Log::warning('Nobitex WebSocket receive failed.', [
                'error_code' => $errorCode,
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

    protected function send(array $payload): void
    {
        $message = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        );

        if (! $this->client->push($message)) {
            throw new RuntimeException(
                "WebSocket push failed: {$this->client->errCode} - {$this->client->errMsg}"
            );
        }
    }

    public function subscribe(string $channel): void
    {
        $mode = OrderBookMode::tryFrom(
            config('nobitex.orderbook_mode', 'snapshot')
        );

        if ($mode === null) {
            throw new UnexpectedValueException(
                'Invalid orderbook mode configuration.'
            );
        }

        $subscribePayload = [
            'channel' => $channel,
        ];

        $recoveryPosition = null;

        if ($mode === OrderBookMode::DELTA) {
            $recoveryPosition = $this->subscriptionManager
                ->getRecoveryPosition($channel);

            if ($recoveryPosition !== null) {
                $subscribePayload['recover'] = true;
                $subscribePayload['epoch'] = $recoveryPosition['epoch'];
                $subscribePayload['offset'] = $recoveryPosition['offset'];
            }
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

        Log::info('Nobitex subscription request sent', [
            'channel' => $channel,
            'request_id' => $requestId,
            'mode' => $mode->value,
            'recovery_requested' => $recoveryPosition !== null,
            'recovery_position' => $recoveryPosition,
        ]);
    }

    public function listen(): void
    {
        while (true) {
            $raw = $this->receive();

            if ($raw === false) {
                continue;
            }

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
                ! is_array($response)
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

    private function handleSubscribeReply(int $requestId, array $response): void
    {
        $pending = $this->pendingSubscriptions[$requestId];
        unset($this->pendingSubscriptions[$requestId]);

        $channel = $pending['channel'];

        $mode = OrderBookMode::tryFrom(
            config('nobitex.orderbook_mode', 'snapshot')
        );

        if ($mode === null) {
            throw new UnexpectedValueException(
                'Invalid orderbook mode configuration.'
            );
        }

        if (isset($response['error'])) {
            if ($mode === OrderBookMode::DELTA) {
                $this->subscriptionManager->invalidate($channel);
            }

            throw new RuntimeException(
                "Subscribe failed for {$channel}: ".json_encode($response['error'])
            );
        }

        $result = $response['subscribe'] ?? null;

        if (! is_array($result)) {
            throw new RuntimeException(
                "Invalid Subscribe response for {$channel}."
            );
        }

        $publications = $result['publications'] ?? [];

        if (! is_array($publications)) {
            throw new RuntimeException(
                "Invalid publications in Subscribe response for {$channel}."
            );
        }

        // Snapshot: بدون Recovery و مدیریت Offset
        if ($mode === OrderBookMode::SNAPSHOT) {
            Log::info('Nobitex snapshot subscription established', [
                'channel' => $channel,
            ]);
            // بخش اسنپ شات
            foreach ($publications as $publication) {
                if (! is_array($publication)) {
                    Log::warning('Invalid publication in subscribe response.', [
                        'channel' => $channel,
                    ]);

                    continue;
                }

                $this->handlePublication([
                    'push' => [
                        'channel' => $channel,
                        'pub' => $publication,
                    ],
                ]);
            }

            return;
        }

        // Delta: ثبت موقعیت و مدیریت Recovery
        $wasRecovering = array_key_exists('was_recovering', $result)
            ? (bool) $result['was_recovering']
            : $pending['recovery_requested'];

        $recovered = (bool) ($result['recovered'] ?? false);

        $recoveryAccepted = $this->subscriptionManager->updateSubscription(
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

        if ($wasRecovering && ! $recovered) {
            Log::warning('Nobitex subscription recovery failed', [
                'channel' => $channel,
            ]);
        }

        $publicationsProcessed = true;
        // بخش دلتا
        foreach ($publications as $publication) {
            if (! is_array($publication)) {
                $publicationsProcessed = false;
                $this->subscriptionManager->invalidate($channel);
                break;
            }

            $processed = $this->handlePublication([
                'push' => [
                    'channel' => $channel,
                    'pub' => $publication,
                ],
            ]);

            if (! $processed) {
                $publicationsProcessed = false;
                break;
            }
        }

        // فقط پس از Recovery موفق و پردازش تمام Publicationها
        if ($recoveryAccepted && $publicationsProcessed) {
            if (preg_match(
                '/^public:orderbook-([A-Z0-9]+)$/',
                $channel,
                $matches
            )) {
                $symbol = $matches[1];

                if ($this->marketDataManager->getHandler($symbol) !== null) {
                    $resumed = $this->marketDataManager->resumeMarket($symbol);

                    if (! $resumed) {
                        Log::warning('Order book could not resume after recovery.', [
                            'channel' => $channel,
                        ]);
                    }
                }
            }
        }
    }

    private function handlePublication(array $message): bool
    {
        $push = $message['push'] ?? [];
        $channel = $push['channel'] ?? null;
        $publication = $push['pub'] ?? null;

        if (! is_string($channel) || ! is_array($publication)) {
            Log::warning('Invalid Nobitex publication envelope.');

            return false;
        }

        if (! preg_match('/^public:orderbook-([A-Z0-9]+)$/', $channel, $matches)) {
            Log::warning('Invalid orderbook channel.', ['channel' => $channel]);

            return false;
        }

        $symbol = $matches[1];

        $mode = OrderBookMode::tryFrom(
            config('nobitex.orderbook_mode', 'snapshot')
        );

        if ($mode === null) {
            throw new UnexpectedValueException(
                'Invalid orderbook mode configuration.'
            );
        }

        // Snapshot: هر پیام یک دفتر سفارش کامل و مستقل است.
        if ($mode === OrderBookMode::SNAPSHOT) {
            $handler = $this->marketDataManager->getHandler($symbol);

            if ($handler === null) {
                Log::warning('Publication received for unregistered market.', [
                    'symbol' => $symbol,
                ]);

                return false;
            }

            $processed = $this->messageRouter->route($message);

            if (! $processed) {
                $this->marketDataManager->invalidateMarket($symbol);

                Log::warning('Snapshot processing failed.', [
                    'channel' => $channel,
                ]);

                return false;
            }

            if (! $handler->isValid()) {
                Log::debug('Snapshot ignored or market is not ready yet.', [
                    'channel' => $channel,
                ]);
            }

            return true;
        }

        // Delta: منطق Offset و Recovery برای پیاده‌سازی آینده حفظ می‌شود.
        $rawOffset = $publication['offset'] ?? null;

        if (
            ! is_int($rawOffset) &&
            ! (is_string($rawOffset) && ctype_digit($rawOffset))
        ) {
            Log::warning('Invalid publication offset.', [
                'channel' => $channel,
                'offset' => $rawOffset,
            ]);

            $this->subscriptionManager->invalidate($channel);

            return false;
        }

        $offset = (int) $rawOffset;

        $subscription = $this->subscriptionManager->get($channel);

        if ($subscription === null) {
            Log::warning('Publication received for unknown subscription.', [
                'channel' => $channel,
            ]);

            return false;
        }

        if ($offset <= $subscription['offset']) {
            Log::debug('Ignoring duplicate or old publication.', [
                'channel' => $channel,
                'incoming_offset' => $offset,
                'current_offset' => $subscription['offset'],
            ]);

            return true;
        }

        if (
            $subscription['positioned'] &&
            $offset > $subscription['offset'] + 1
        ) {
            Log::error('Centrifugo publication gap detected.', [
                'channel' => $channel,
                'expected_offset' => $subscription['offset'] + 1,
                'received_offset' => $offset,
            ]);

            throw new RuntimeException(
                "Publication gap detected for {$channel}."
            );
        }

        $processed = $this->messageRouter->route($message);

        if (! $processed) {
            $this->subscriptionManager->invalidate($channel);

            if ($this->marketDataManager->getHandler($symbol) !== null) {
                $this->marketDataManager->invalidateMarket($symbol);
            }

            Log::warning('Order book publication processing failed.', [
                'channel' => $channel,
                'offset' => $offset,
            ]);

            return false;
        }

        $updated = $this->subscriptionManager->updateOffset($channel, $offset);

        if (! $updated) {
            Log::warning('Failed to update subscription offset.', [
                'channel' => $channel,
                'offset' => $offset,
            ]);
        }

        return $updated;
    }

    private function handleHeartbeat(): void
    {
        if (! $this->client->push('{}')) {
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
