<?php

namespace App\Data;

final readonly class MdrCalculationResult
{
    public function __construct(
        public string $normalizedChannel,
        public float $grossAmount,
        public float $mdrFee,
        public float $netSettlementAmount,
    ) {}
}
