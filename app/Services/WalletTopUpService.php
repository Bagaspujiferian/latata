<?php

namespace App\Services;

use App\Models\WalletTopUp;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletTopUpService
{
    public const MIN_AMOUNT = 10000.00;

    public const MAX_AMOUNT = 10000000.00;

    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly NotificationService $notificationService,
    ) {}

    public function createTopUp(int $userId, float $amount, string $channel = 'midtrans_snap'): WalletTopUp
    {
        if ($amount < self::MIN_AMOUNT) {
            throw new DomainException('Minimal nominal Top-Up saldo adalah Rp '.number_format(self::MIN_AMOUNT, 0, ',', '.').'.');
        }

        if ($amount > self::MAX_AMOUNT) {
            throw new DomainException('Maksimal nominal Top-Up saldo per transaksi adalah Rp '.number_format(self::MAX_AMOUNT, 0, ',', '.').'.');
        }

        $allowedChannels = ['midtrans_snap', 'qris', 'bca_va', 'bri_va', 'gopay'];
        $cleanChannel = in_array($channel, $allowedChannels, true) ? $channel : 'midtrans_snap';
        $topupCode = 'TOPUP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));

        return WalletTopUp::create([
            'topup_code' => $topupCode,
            'user_id' => $userId,
            'amount' => number_format(round($amount, 2), 2, '.', ''),
            'payment_channel' => $cleanChannel,
            'status' => 'UNPAID',
            'expires_at' => now()->addHours(2),
        ]);
    }

    /**
     * Atomically settle a wallet top-up (idempotent) and mark credited funds as non-withdrawable (Anti-Gestun).
     */
    public function settleTopUp(string $topupCode, string $gatewayRef): WalletTopUp
    {
        return DB::transaction(function () use ($topupCode, $gatewayRef): WalletTopUp {
            $topupRow = DB::table('wallet_topups')
                ->where('topup_code', $topupCode)
                ->lockForUpdate()
                ->first();

            if (! $topupRow) {
                throw new DomainException("Tagihan Top-Up [{$topupCode}] tidak ditemukan.");
            }

            // Strict Idempotency: if already PAID, return cleanly with zero duplicate credit!
            if ($topupRow->status === 'PAID') {
                return WalletTopUp::findOrFail((int) $topupRow->id);
            }

            if ($topupRow->status !== 'UNPAID') {
                throw new DomainException("Tagihan Top-Up {$topupCode} sudah berstatus {$topupRow->status} dan tidak dapat dilunasi.");
            }

            $amount = round((float) $topupRow->amount, 2);

            // Credit available_balance + increment non_withdrawable_portion atomically under wallet lock.
            $this->ledgerService->creditWalletTopUp(
                userId: (int) $topupRow->user_id,
                amount: $amount,
                topupCode: $topupRow->topup_code,
            );

            DB::table('wallet_topups')
                ->where('id', $topupRow->id)
                ->update([
                    'status' => 'PAID',
                    'gateway_reference' => mb_substr(trim($gatewayRef), 0, 100),
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            $topup = WalletTopUp::findOrFail((int) $topupRow->id);
            $userId = (int) $topup->user_id;
            $topupId = (int) $topup->id;

            DB::afterCommit(function () use ($userId, $amount, $topupCode, $topupId): void {
                $this->notificationService->notify(
                    userId: $userId,
                    category: 'escrow',
                    title: 'Top-Up Dompet Rp '.number_format($amount, 0, ',', '.').' Berhasil',
                    body: "Tagihan {$topupCode} lunas. Saldo siap digunakan untuk belanja di NexaTrade (dilindungi kebijakan Anti-Gestun).",
                    actionUrl: '/buyer/wallet',
                    refType: 'wallet_topup',
                    refId: $topupId,
                );
            });

            return $topup;
        });
    }
}
