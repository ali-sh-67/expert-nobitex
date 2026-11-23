<?php

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

\Swoole\Coroutine\run(function () {
    $client = new Client('ws.nobitex.ir', 443, true);

    $client->set([
        'timeout' => 10,
        'websocket_mask' => true,
        'websocket_compression' => false,
    ]);

    try {
        echo "Connecting...\n";

        if (! $client->upgrade('/connection/websocket')) {
            echo "Upgrade failed: {$client->errCode} - {$client->errMsg}\n";

            return;
        }

        echo "Connected. Sending Centrifuge connect request...\n";

        if (! $client->push(json_encode([
            'connect' => (object) [],
            'id' => 1,
        ], JSON_THROW_ON_ERROR))) {
            echo "Push failed: {$client->errCode} - {$client->errMsg}\n";

            return;
        }

        $response = $client->recv(10);

        if (! $response instanceof Frame) {
            echo 'Connect response failed: ';
            var_dump($response);
            echo "errCode={$client->errCode}; errMsg={$client->errMsg}\n";

            return;
        }

        echo "Connect response received.\n";
        echo "Waiting for a message without subscribing...\n";

        $startedAt = microtime(true);
        $result = $client->recv(5);
        $elapsed = round(microtime(true) - $startedAt, 3);

        echo "Elapsed: {$elapsed}s\n";
        echo 'Result type: '.get_debug_type($result)."\n";
        echo "errCode={$client->errCode}; errMsg={$client->errMsg}\n";

        if ($result === false) {
            if ($client->errCode === SOCKET_ETIMEDOUT) {
                echo "Result: timeout\n";
            } else {
                echo "Result: receive error\n";
            }
        } elseif ($result === '') {
            echo "Result: server closed the connection\n";
        } elseif ($result instanceof Frame) {
            echo "Result: a frame was received\n";
        }
    } finally {
        $client->close();
    }
});
