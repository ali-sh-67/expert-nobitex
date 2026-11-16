<?php

namespace App\Services\Nobitex;

use InvalidArgumentException;

readonly class StrategyResult
{
    public function __construct(
        public Signal $signal,
        public float $confidence,
        public string $reason,
        public array $metadata = [],
    ) {
        if (
            !is_finite($confidence) ||
            $confidence < 0 ||
            $confidence > 1
        ) {
            throw new InvalidArgumentException(
                'Confidence must be between 0 and 1.'
            );
        }
    }
}
