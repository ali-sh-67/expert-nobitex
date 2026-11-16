<?php

namespace App\Services\Nobitex;

class MarketDataMessageRouter
{
    public function __construct(
        private MarketDataManager $marketDataManager,
    ) {
    }

    public function route(array $message): bool
    {
        $push = $message['push'] ?? null;

        if (!is_array($push)) {
            return false;
        }

        $channel = $push['channel'] ?? null;

        if (
            !is_string($channel) ||
            !preg_match(
                '/^public:orderbook-([A-Z0-9]+)$/',
                $channel,
                $matches
            )
        ) {
            return false;
        }

        $symbol = $matches[1];

        $handler = $this->marketDataManager->getHandler($symbol);

        if ($handler === null) {
            return false;
        }

        $rawData = $push['pub']['data'] ?? null;

        if (is_string($rawData)) {
            try {
                $data = json_decode(
                    $rawData,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                return false;
            }
        } else {
            $data = $rawData;
        }

        if (!is_array($data)) {
            return false;
        }

        $this->marketDataManager->handle($symbol, $data);

        return true;
    }
}
