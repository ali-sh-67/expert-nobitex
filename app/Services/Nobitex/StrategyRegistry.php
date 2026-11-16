<?php

namespace App\Services\Nobitex;

use InvalidArgumentException;

class StrategyRegistry
{
    /** @var array<string, StrategyInterface> */
    private array $strategies = [];

    public function __construct(iterable $strategies)
    {
        foreach ($strategies as $strategy) {
            $key = $strategy->key();

            if (isset($this->strategies[$key])) {
                throw new InvalidArgumentException(
                    "Duplicate strategy key: {$key}"
                );
            }

            $this->strategies[$key] = $strategy;
        }
    }

    public function get(string $key): ?StrategyInterface
    {
        return $this->strategies[$key] ?? null;
    }

    /** @return array<string, StrategyInterface> */
    public function all(): array
    {
        return $this->strategies;
    }
}
