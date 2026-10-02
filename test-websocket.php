<?php

Co\run(function () {
    $client = new Swoole\Coroutine\Http\Client(
        'ws.nobitex.ir',
        443,
        true
    );

    $client->set([
        'timeout' => 10,
        'websocket_mask' => true,
        'websocket_compression' => false,
    ]);

    echo "Connecting...\n";

    if (!$client->upgrade('/connection/websocket')) {
        echo "Upgrade failed\n";
        echo "Error: {$client->errCode} - {$client->errMsg}\n";
        return;
    }

    echo "WebSocket connected! HTTP {$client->getStatusCode()}\n";

    // 1. Connect
    $message = json_encode([
        'connect' => (object)[],
        'id' => 1,
    ]);

    echo "Sending connect...\n";

    if (!$client->push($message)) {
        echo "Connect push failed\n";
        echo "Error: {$client->errCode} - {$client->errMsg}\n";
        return;
    }

    echo "Connect message sent.\n";

    $frame = $client->recv(10);

    if ($frame === false) {
        echo "Connect response failed\n";
        echo "Error: {$client->errCode} - {$client->errMsg}\n";
        return;
    }

    echo "Connect response:\n";
    echo $frame->data . PHP_EOL;

    // 2. Subscribe
    $subscribeMessage = json_encode([
        'id' => 2,
        'subscribe' => [
            'channel' => 'public:orderbook-BTCIRT',
        ],
    ]);

    echo "Sending subscribe...\n";

    if (!$client->push($subscribeMessage)) {
        echo "Subscribe push failed\n";
        echo "Error: {$client->errCode} - {$client->errMsg}\n";
        return;
    }

    echo "Subscribe message sent.\n";

    $frame = $client->recv(10);

    if ($frame === false) {
        echo "Subscribe response failed\n";
        echo "Error: {$client->errCode} - {$client->errMsg}\n";
        return;
    }

    echo "Subscribe response:\n";
    echo "Waiting for messages...\n";

    while (true) {
        $frame = $client->recv(30);

        if ($frame === false) {
            echo "Receive failed\n";
            echo "Error: {$client->errCode} - {$client->errMsg}\n";
            break;
        }

        // در Swoole باید Frame را بررسی کنیم
        if ($frame instanceof Swoole\WebSocket\Frame) {
            $raw = $frame->data;
        } elseif (is_string($frame)) {
            // برای سازگاری با خروجی احتمالی نسخه فعلی
            $raw = $frame;
        } else {
            echo "Unknown frame type: ";
            var_dump($frame);
            continue;
        }

        echo "Raw message: {$raw}\n";

        $message = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Invalid JSON\n";
            continue;
        }

        /*
         * Centrifugo application-level ping
         *
         * پیام {} از طرف سرور
         */
        if ($message === []) {
            echo "Ping received -> sending pong\n";

            if (!$client->push('{}')) {
                echo "Pong failed\n";
                echo "Error: {$client->errCode} - {$client->errMsg}\n";
                break;
            }

            echo "Pong sent\n";
            continue;
        }

        /*
         * Order Book publication
         */
        if (isset($message['push']['pub']['data'])) {
            $data = $message['push']['pub']['data'];

            echo "OrderBook update\n";
            echo "Last price: " . ($data['lastTradePrice'] ?? 'N/A') . PHP_EOL;

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

            continue;
        }

        echo "Other message:\n";
        var_dump($message);
    }
    $client->close();
});
