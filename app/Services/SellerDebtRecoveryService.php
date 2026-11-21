<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\DebtRepayment;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SellerDebtRecoveryService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly NotificationService $notificationService,
    ) {}

    public function repayDebtFromAvailableBalance(int $sellerId, float $amount): DebtRepayment
    {
        if ($amount <= 0) {
            throw new DomainException('Nominal pelunasan tunggakan harus lebih dari Rp 0.');
        }

        return DB::transaction(function () use ($sellerId, $amount): DebtRepayment {
            $wallet = DB::table('wallets')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            $profile = DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            if (! $wallet || ! $profile) {
                throw new DomainException('Dompet atau profil toko penjual tidak ditemukan.');
            }

            $debtBeforeCents = (int) round((float) $wallet->clawback_debt_balance * 100);
            if ($debtBeforeCents <= 0) {
                throw new DomainException('Toko Anda tidak memiliki tunggakan clawback garansi yang perlu dilunasi.');
            }

            $requestedCents = (int) round($amount * 100);
            $payCents = min($requestedCents, $debtBeforeCents);
            $availableCents = (int) round((float) $wallet->available_balance * 100);

            if ($availableCents < $payCents) {
                throw new InsufficientWalletBalanceException(
                    'Saldo Aktif tidak mencukupi untuk pelunasan tunggakan sebesar Rp '.number_format($payCents / 100, 0, ',', '.').'.'
                );
            }

            $payAmount = round($payCents / 100, 2);
            $debtAfterCents = $debtBeforeCents - $payCents;
            $debtAfter = round($debtAfterCents / 100, 2);
            $repaymentCode = 'REPAY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

            $this->ledgerService->debitForSellerDebtRecovery(
                sellerId: $sellerId,
                amount: $payAmount,
                repaymentCode: $repaymentCode,
                alreadyLocked: true,
            );

            DB::table('wallets')->where('user_id', $sellerId)->update([
                'clawback_debt_balance' => number_format($debtAfter, 2, '.', ''),
                'updated_at' => now(),
            ]);

            $autoUnsuspended = false;
            if ($debtAfterCents === 0 && (bool) $profile->is_suspended) {
                DB::table('seller_profiles')->where('user_id', $sellerId)->update([
                    'is_suspended' => false,
                    'suspension_reason' => null,
                    'is_store_open' => true,
                    'updated_at' => now(),
                ]);
                $autoUnsuspended = true;
            }

            $repayment = DebtRepayment::create([
                'repayment_code' => $repaymentCode,
                'seller_id' => $sellerId,
                'amount' => number_format($payAmount, 2, '.', ''),
                'payment_source' => 'available_balance',
                'debt_before' => number_format($debtBeforeCents / 100, 2, '.', ''),
                'debt_after' => number_format($debtAfter, 2, '.', ''),
                'auto_unsuspended' => $autoUnsuspended,
                'notes' => $autoUnsuspended
                    ? 'Pelunasan penuh via Saldo Aktif. Toko otomatis diaktifkan kembali.'
                    : 'Pelunasan sebagian via Saldo Aktif.',
                'created_at' => now(),
            ]);

            $this->notificationService->notify(
                userId: $sellerId,
                category: 'escrow',
                title: $autoUnsuspended ? 'Tunggakan Lunas & Toko Aktif Kembali!' : 'Pembayaran Tunggakan Clawback Diterima',
                body: $autoUnsuspended
                    ? "Pelunasan {$repaymentCode} sebesar Rp ".number_format($payAmount, 0, ',', '.').' telah lunas 100%. Status suspend toko otomatis dicabut dan etalase kembali dibuka.'
                    : "Pembayaran {$repaymentCode} sebesar Rp ".number_format($payAmount, 0, ',', '.').' dicatat. Sisa tunggakan: Rp '.number_format($debtAfter, 0, ',', '.').'.',
                actionUrl: '/seller/wallet',
                refType: 'debt_repayment',
                refId: (int) $repayment->id,
            );

            return $repayment;
        });
    }

    public function repayDebtViaGatewaySettlement(int $sellerId, float $amount, string $gatewayRef): DebtRepayment
    {
        if ($amount <= 0) {
            throw new DomainException('Nominal pelunasan via gateway harus lebih dari Rp 0.');
        }

        return DB::transaction(function () use ($sellerId, $amount, $gatewayRef): DebtRepayment {
            $wallet = DB::table('wallets')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            $profile = DB::table('seller_profiles')
                ->where('user_id', $sellerId)
                ->lockForUpdate()
                ->first();

            if (! $wallet || ! $profile) {
                throw new DomainException('Dompet atau profil toko penjual tidak ditemukan.');
            }

            $debtBeforeCents = (int) round((float) $wallet->clawback_debt_balance * 100);
            if ($debtBeforeCents <= 0) {
                throw new DomainException('Toko Anda tidak memiliki tunggakan clawback garansi.');
            }

            $requestedCents = (int) round($amount * 100);
            $payCents = min($requestedCents, $debtBeforeCents);
            $payAmount = round($payCents / 100, 2);
            $debtAfterCents = $debtBeforeCents - $payCents;
            $debtAfter = round($debtAfterCents / 100, 2);
            $repaymentCode = 'REPAY-GW-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

            DB::table('wallets')->where('user_id', $sellerId)->update([
                'clawback_debt_balance' => number_format($debtAfter, 2, '.', ''),
                'updated_at' => now(),
            ]);

            $autoUnsuspended = false;
            if ($debtAfterCents === 0 && (bool) $profile->is_suspended) {
                DB::table('seller_profiles')->where('user_id', $sellerId)->update([
                    'is_suspended' => false,
                    'suspension_reason' => null,
                    'is_store_open' => true,
                    'updated_at' => now(),
                ]);
                $autoUnsuspended = true;
            }

            $repayment = DebtRepayment::create([
                'repayment_code' => $repaymentCode,
                'seller_id' => $sellerId,
                'amount' => number_format($payAmount, 2, '.', ''),
                'payment_source' => 'gateway_direct',
                'debt_before' => number_format($debtBeforeCents / 100, 2, '.', ''),
                'debt_after' => number_format($debtAfter, 2, '.', ''),
                'auto_unsuspended' => $autoUnsuspended,
                'notes' => "Pelunasan langsung via Gateway Ref {$gatewayRef}".($autoUnsuspended ? ' (Auto-Unsuspend aktif)' : ''),
                'created_at' => now(),
            ]);

            $this->notificationService->notify(
                userId: $sellerId,
                category: 'escrow',
                title: $autoUnsuspended ? 'Tunggakan Lunas via Gateway & Toko Aktif!' : 'Pelunasan Gateway Diterima',
                body: $autoUnsuspended
                    ? "Transfer gateway {$gatewayRef} sebesar Rp ".number_format($payAmount, 0, ',', '.').' melunasi tunggakan clawback Anda. Toko otomatis kembali dibuka.'
                    : "Transfer gateway {$gatewayRef} sebesar Rp ".number_format($payAmount, 0, ',', '.').' dicatat. Sisa tunggakan Rp '.number_format($debtAfter, 0, ',', '.').'.',
                actionUrl: '/seller/wallet',
                refType: 'debt_repayment',
                refId: (int) $repayment->id,
            );

            return $repayment;
        });
    }
}
