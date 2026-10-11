<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class OrderBookHandler
{
    private ?string $lastTradePrice = null {
        get {
            return $this->lastTradePrice;
        }
    }

    private ?int $lastUpdate = null {
        get {
            return $this->lastUpdate;
        }
    }

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $bids = [];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $asks = [];

    private bool $valid = false {
        get {
            return $this->valid;
        }
    }

    public function initialize(array $data): void
    {
        $this->handle($data);
    }

    public function handle(array $data): void
    {
        // هر پیام باید وضعیت کامل هر دو سمت را داشته باشد.
        if (
            !isset($data['bids'], $data['asks']) ||
            !is_array($data['bids']) ||
            !is_array($data['asks'])
        ) {
            $this->invalidate('Missing or invalid bids/asks arrays.');
            return;
        }

        try {
            $bids = $this->normalizeLevels($data['bids']);
            $asks = $this->normalizeLevels($data['asks']);

            $this->validateOrder($bids, 'bids');
            $this->validateOrder($asks, 'asks');

            if ($bids === [] || $asks === []) {
                throw new UnexpectedValueException(
                    'Order book side is empty.'
                );
            }

            $bestBid = BigDecimal::of($bids[0][0]);
            $bestAsk = BigDecimal::of($asks[0][0]);

            if ($bestBid->isGreaterThanOrEqualTo($bestAsk)) {
                throw new UnexpectedValueException(
                    'Best bid is greater than or equal to best ask.'
                );
            }
        } catch (Throwable $e) {
            $this->invalidate($e->getMessage());
            return;
        }

        $this->bids = $bids;
        $this->asks = $asks;
        $this->valid = true;

        if (isset($data['lastTradePrice'])) {
            $this->lastTradePrice = (string) $data['lastTradePrice'];
        }

        if (isset($data['lastUpdate'])) {
            $this->lastUpdate = (int) $data['lastUpdate'];
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     * @throws MathException
     */
    private function normalizeLevels(array $levels): array
    {
        if ($levels === [] || !array_is_list($levels)) {
            throw new UnexpectedValueException(
                'Order book levels must be a non-empty list.'
            );
        }

        $result = [];

        foreach ($levels as $index => $level) {
            if (
                !is_array($level) ||
                !array_key_exists(0, $level) ||
                !array_key_exists(1, $level) ||
                !is_scalar($level[0]) ||
                !is_scalar($level[1])
            ) {
                throw new UnexpectedValueException(
                    "Malformed order book level at index $index."
                );
            }

            $price = (string) $level[0];
            $quantity = (string) $level[1];

            try {
                $priceDecimal = BigDecimal::of($price);
                $quantityDecimal = BigDecimal::of($quantity);
            } catch (Throwable $e) {
                throw new UnexpectedValueException(
                    "Non-numeric price or quantity at level $index.",
                    previous: $e,
                );
            }

            if (!$priceDecimal->isGreaterThan(BigDecimal::zero())) {
                throw new UnexpectedValueException(
                    "Price must be positive at level $index."
                );
            }

            if ($quantityDecimal->isLessThan(BigDecimal::zero())) {
                throw new UnexpectedValueException(
                    "Quantity cannot be negative at level $index."
                );
            }

            $result[] = [$price, $quantity];
        }

        return $result;
    }

    private function validateOrder(array $levels, string $side): void
    {
        for ($i = 1, $count = count($levels); $i < $count; $i++) {
            $previous = BigDecimal::of($levels[$i - 1][0]);
            $current = BigDecimal::of($levels[$i][0]);

            $validOrder = $side === 'bids'
                ? $previous->isGreaterThan($current)
                : $previous->isLessThan($current);

            if (!$validOrder) {
                throw new UnexpectedValueException(
                    "Invalid price order in $side at level $i."
                );
            }
        }
    }

    private function invalidate(string $reason): void
    {
        $this->valid = false;
        $this->bids = [];
        $this->asks = [];

        Log::warning('Invalid Nobitex order book; calculations disabled.', [
            'reason' => $reason,
        ]);
    }

    public function getBestBidPrice(): ?string
    {
        return $this->valid ? ($this->bids[0][0] ?? null) : null;
    }

    public function getBestBidQuantity(): ?string
    {
        return $this->valid ? ($this->bids[0][1] ?? null) : null;
    }

    public function getBestAskPrice(): ?string
    {
        return $this->valid ? ($this->asks[0][0] ?? null) : null;
    }

    public function getBestAskQuantity(): ?string
    {
        return $this->valid ? ($this->asks[0][1] ?? null) : null;
    }

    public function getBidDepth(int $levels): ?string
    {
        if (!$this->valid) {
            return null;
        }

        return max(0, $levels)
                |> (fn($x) => array_slice($this->bids, 0, $x))
                |> $this->calculateDepth(...);
    }

    public function getAskDepth(int $levels): ?string
    {
        if (!$this->valid) {
            return null;
        }

        return max(0, $levels)
                |> (fn($x) => array_slice($this->asks, 0, $x))
                |> $this->calculateDepth(...);
    }

    private function calculateDepth(array $levels): string
    {
        $total = BigDecimal::zero();

        foreach ($levels as $level) {
            $total = $total->plus(BigDecimal::of($level[1]));
        }

        return $total->__toString();
    }

    public function snapshot(): OrderBookSnapshot
    {
        return new OrderBookSnapshot(
            lastTradePrice: $this->lastTradePrice,
            bestBidPrice: $this->getBestBidPrice(),
            bestBidQuantity: $this->getBestBidQuantity(),
            bestAskPrice: $this->getBestAskPrice(),
            bestAskQuantity: $this->getBestAskQuantity(),
            bidDepth: $this->getBidDepth(5),
            askDepth: $this->getAskDepth(5),
            lastUpdate: $this->lastUpdate,
        );
    }

    public function getBidCount(): int
    {
        return count($this->bids);
    }

    public function getAskCount(): int
    {
        return count($this->asks);
    }
}
