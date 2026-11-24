<?php

namespace App\Services;

use App\Data\MdrCalculationResult;

class PaymentMdrCalculatorService
{
    /**
     * Calculate payment gateway Merchant Discount Rate (MDR) fee and net settlement amount.
     * Pure domain service: never mutates user custody wallets or wallet_mutations.
     */
    public function calculate(string $paymentType, float $grossAmount): MdrCalculationResult
    {
        $normalizedChannel = $this->normalizeChannel($paymentType);
        $gross = round(max(0.0, $grossAmount), 2);

        if ($gross <= 0.0) {
            return new MdrCalculationResult(
                normalizedChannel: $normalizedChannel,
                grossAmount: 0.00,
                mdrFee: 0.00,
                netSettlementAmount: 0.00,
            );
        }

        /** @var array<string, array{type?: string, rate?: float|int, flat?: float|int}> $rates */
        $rates = (array) config('nexatrade.mdr_rates', []);
        $rule = $rates[$normalizedChannel] ?? $rates['midtrans_snap'] ?? [
            'type' => 'flat',
            'rate' => 0.0,
            'flat' => 4000.00,
        ];

        $type = (string) ($rule['type'] ?? 'flat');
        $rate = (float) ($rule['rate'] ?? 0.0);
        $flat = (float) ($rule['flat'] ?? 0.0);

        $rawFee = match ($type) {
            'percent' => $gross * $rate,
            'percent_plus_flat' => ($gross * $rate) + $flat,
            default => $flat,
        };

        $mdrFee = round(max(0.0, $rawFee), 2);
        $netSettlement = round(max(0.0, $gross - $mdrFee), 2);

        return new MdrCalculationResult(
            normalizedChannel: $normalizedChannel,
            grossAmount: $gross,
            mdrFee: $mdrFee,
            netSettlementAmount: $netSettlement,
        );
    }

    public function normalizeChannel(string $rawPaymentType): string
    {
        $clean = strtolower(trim($rawPaymentType));

        if (str_starts_with($clean, 'hybrid_wallet_')) {
            $clean = substr($clean, strlen('hybrid_wallet_'));
        }

        if ($clean === 'wallet_full') {
            return 'wallet';
        }

        /** @var array<string, mixed> $rates */
        $rates = (array) config('nexatrade.mdr_rates', []);

        if ($clean !== '' && array_key_exists($clean, $rates)) {
            return $clean;
        }

        return 'midtrans_snap';
    }
}
