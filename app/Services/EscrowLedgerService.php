<?php

namespace App\Services;

use App\Exceptions\FinancialInvariantException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\WalletMutation;
use Illuminate\Support\Facades\DB;

class EscrowLedgerService
{
    public function holdSellerEscrow(int $sellerId, int $orderId, float $amount, string $orderCode): WalletMutation
    {
        return DB::transaction(function () use ($sellerId, $orderId, $amount, $orderCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'pending',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'ESCROW_HOLD',
                referenceCode: "HOLD-{$orderCode}",
                description: "Dana escrow tertahan untuk pesanan {$orderCode}",
                orderId: $orderId,
                paymentInvoiceId: null,
            );
        });
    }

    /**
     * @return array{debit: WalletMutation, credit: WalletMutation}
     */
    public function releaseEscrowToAvailable(int $sellerId, int $orderId, float $amount, string $orderCode): array
    {
        return DB::transaction(function () use ($sellerId, $orderId, $amount, $orderCode): array {
            $this->lockWalletsAscending([$sellerId]);

            $debit = $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'pending',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: 'ESCROW_RELEASE',
                referenceCode: "REL-DEBIT-{$orderCode}",
                description: "Pelepasan dana escrow pesanan {$orderCode} dari pending",
                orderId: $orderId,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            $credit = $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'ESCROW_RELEASE',
                referenceCode: "REL-CREDIT-{$orderCode}",
                description: "Pencairan dana escrow pesanan {$orderCode} ke saldo aktif",
                orderId: $orderId,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * @return array{seller_debit: WalletMutation, buyer_credit: WalletMutation}
     */
    public function refundEscrowToBuyer(
        int $sellerId,
        int $buyerId,
        int $orderId,
        float $sellerEscrowAmount,
        float $buyerRefundAmount,
        string $orderCode,
        string $reason,
    ): array {
        return DB::transaction(function () use (
            $sellerId,
            $buyerId,
            $orderId,
            $sellerEscrowAmount,
            $buyerRefundAmount,
            $orderCode,
            $reason,
        ): array {
            // Deadlock Prevention Law: always lock multiple wallets in ascending user_id order.
            $this->lockWalletsAscending([$sellerId, $buyerId]);

            $sellerDebit = $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'pending',
                direction: 'DEBIT',
                amount: $sellerEscrowAmount,
                mutationType: 'ESCROW_REFUND_DEBIT',
                referenceCode: "REF-DEBIT-{$orderCode}",
                description: "Penarikan escrow refund {$orderCode}: {$reason}",
                orderId: $orderId,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            $buyerCredit = $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $buyerRefundAmount,
                mutationType: 'BUYER_REFUND_CREDIT',
                referenceCode: "REF-CREDIT-{$orderCode}",
                description: "Refund 100% pesanan {$orderCode}: {$reason}",
                orderId: $orderId,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            return [
                'seller_debit' => $sellerDebit,
                'buyer_credit' => $buyerCredit,
            ];
        });
    }

    public function deductBuyerWalletForCheckout(
        int $buyerId,
        int $invoiceId,
        float $amount,
        string $invoiceCode,
        bool $isHybrid,
    ): WalletMutation {
        return DB::transaction(function () use ($buyerId, $invoiceId, $amount, $invoiceCode, $isHybrid): WalletMutation {
            $this->lockWalletsAscending([$buyerId]);

            $mutationType = $isHybrid ? 'HYBRID_WALLET_HOLD' : 'PAY_ORDER_FULL_WALLET';
            $referenceCode = $isHybrid ? "HYB-HOLD-{$invoiceCode}" : "PAY-WALLET-{$invoiceCode}";
            $description = $isHybrid
                ? "Penahanan saldo hibrida untuk tagihan {$invoiceCode}"
                : "Pembayaran penuh via saldo dompet untuk tagihan {$invoiceCode}";

            $wasExisting = WalletMutation::where('reference_code', $referenceCode)->exists();

            $mutation = $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: $mutationType,
                referenceCode: $referenceCode,
                description: $description,
                orderId: null,
                paymentInvoiceId: $invoiceId,
                alreadyLocked: true,
            );

            // Anti-Gestun Erosion Law: wallet shopping spends non-withdrawable Top-Up funds first.
            if (! $wasExisting) {
                $walletRow = DB::table('wallets')->where('user_id', $buyerId)->first();
                $currentNonWithdrawableCents = max(0, $this->toCents((float) ($walletRow->non_withdrawable_portion ?? 0)));
                if ($currentNonWithdrawableCents > 0) {
                    $newNonWithdrawableCents = max(0, $currentNonWithdrawableCents - $this->toCents($amount));
                    DB::table('wallets')
                        ->where('user_id', $buyerId)
                        ->update([
                            'non_withdrawable_portion' => $this->fromCents($newNonWithdrawableCents),
                            'updated_at' => now(),
                        ]);
                }
            }

            return $mutation;
        });
    }

    /**
     * Credit buyer wallet via official Top-Up channel and lock credited amount as non-withdrawable (Anti-Gestun).
     */
    public function creditWalletTopUp(int $userId, float $amount, string $topupCode): WalletMutation
    {
        return DB::transaction(function () use ($userId, $amount, $topupCode): WalletMutation {
            $this->lockWalletsAscending([$userId]);

            $referenceCode = "TOPUP-{$topupCode}";
            $wasExisting = WalletMutation::where('reference_code', $referenceCode)->exists();

            $mutation = $this->mutateLockedWallet(
                userId: $userId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'WALLET_TOPUP',
                referenceCode: $referenceCode,
                description: "Top-Up saldo dompet {$topupCode} (khusus belanja / Anti-Gestun)",
                orderId: null,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            if (! $wasExisting) {
                $walletRow = DB::table('wallets')->where('user_id', $userId)->first();
                $currentNonWithdrawableCents = max(0, $this->toCents((float) ($walletRow->non_withdrawable_portion ?? 0)));
                $newNonWithdrawableCents = $currentNonWithdrawableCents + $this->toCents($amount);

                DB::table('wallets')
                    ->where('user_id', $userId)
                    ->update([
                        'non_withdrawable_portion' => $this->fromCents($newNonWithdrawableCents),
                        'updated_at' => now(),
                    ]);
            }

            return $mutation;
        });
    }

    /**
     * Execute Partial Dispute Split Settlement across Seller Pending, Seller Available, and Buyer Available.
     * Guarantees seller pending drops by 100% of seller_escrow_amount and buyer refund stays withdrawable.
     *
     * @return array{
     *   seller_release_debit: ?WalletMutation,
     *   seller_release_credit: ?WalletMutation,
     *   seller_clawback_debit: ?WalletMutation,
     *   buyer_refund_credit: ?WalletMutation
     * }
     */
    public function executeDisputeSplitSettlement(
        int $sellerId,
        int $buyerId,
        int $orderId,
        float $sellerEscrowAmount,
        float $sellerReleaseAmount,
        float $buyerRefundAmount,
        string $orderCode,
        string $reason,
    ): array {
        $escrowCents = $this->toCents($sellerEscrowAmount);
        $releaseCents = $this->toCents($sellerReleaseAmount);
        $buyerRefundCents = $this->toCents($buyerRefundAmount);
        $clawbackCents = $escrowCents - $releaseCents;

        if ($escrowCents <= 0 || $releaseCents < 0 || $clawbackCents < 0 || $buyerRefundCents < 0) {
            throw new FinancialInvariantException('Nominal Split Settlement tidak valid terhadap escrow pesanan.');
        }

        return DB::transaction(function () use (
            $sellerId,
            $buyerId,
            $orderId,
            $releaseCents,
            $clawbackCents,
            $buyerRefundCents,
            $orderCode,
            $reason,
        ): array {
            $this->lockWalletsAscending([$sellerId, $buyerId]);

            $sellerReleaseDebit = null;
            $sellerReleaseCredit = null;
            $sellerClawbackDebit = null;
            $buyerRefundCredit = null;

            if ($releaseCents > 0) {
                $releaseDecimal = $releaseCents / 100;
                $sellerReleaseDebit = $this->mutateLockedWallet(
                    userId: $sellerId,
                    bucket: 'pending',
                    direction: 'DEBIT',
                    amount: $releaseDecimal,
                    mutationType: 'DISPUTE_PARTIAL_RELEASE',
                    referenceCode: "SPLIT-REL-D-{$orderCode}",
                    description: "Split Settlement: pelepasan porsi escrow penjual {$orderCode} ({$reason})",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );

                $sellerReleaseCredit = $this->mutateLockedWallet(
                    userId: $sellerId,
                    bucket: 'available',
                    direction: 'CREDIT',
                    amount: $releaseDecimal,
                    mutationType: 'DISPUTE_PARTIAL_RELEASE',
                    referenceCode: "SPLIT-REL-C-{$orderCode}",
                    description: "Split Settlement: pencairan porsi penjual {$orderCode} ke saldo aktif",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );
            }

            if ($clawbackCents > 0) {
                $sellerClawbackDebit = $this->mutateLockedWallet(
                    userId: $sellerId,
                    bucket: 'pending',
                    direction: 'DEBIT',
                    amount: $clawbackCents / 100,
                    mutationType: 'DISPUTE_PARTIAL_REFUND',
                    referenceCode: "SPLIT-CLAW-{$orderCode}",
                    description: "Split Settlement: penarikan sisa escrow porsi refund {$orderCode}",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );
            }

            if ($buyerRefundCents > 0) {
                // Refund credit goes directly into buyer available_balance WITHOUT touching non_withdrawable_portion.
                $buyerRefundCredit = $this->mutateLockedWallet(
                    userId: $buyerId,
                    bucket: 'available',
                    direction: 'CREDIT',
                    amount: $buyerRefundCents / 100,
                    mutationType: 'DISPUTE_PARTIAL_REFUND',
                    referenceCode: "SPLIT-BUY-{$orderCode}",
                    description: "Split Settlement: refund parsial pesanan {$orderCode} ({$reason})",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );
            }

            return [
                'seller_release_debit' => $sellerReleaseDebit,
                'seller_release_credit' => $sellerReleaseCredit,
                'seller_clawback_debit' => $sellerClawbackDebit,
                'buyer_refund_credit' => $buyerRefundCredit,
            ];
        });
    }

    public function refundExpiredHybridWalletHold(
        int $buyerId,
        int $invoiceId,
        float $amount,
        string $invoiceCode,
    ): WalletMutation {
        return DB::transaction(function () use ($buyerId, $invoiceId, $amount, $invoiceCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'HYBRID_EXPIRY_REFUND',
                referenceCode: "HYB-EXP-{$invoiceCode}",
                description: "Pengembalian otomatis saldo hibrida karena tagihan {$invoiceCode} kedaluwarsa",
                orderId: null,
                paymentInvoiceId: $invoiceId,
            );
        });
    }

    public function creditZombiePaymentRefund(
        int $buyerId,
        int $invoiceId,
        float $amount,
        string $invoiceCode,
    ): WalletMutation {
        return DB::transaction(function () use ($buyerId, $invoiceId, $amount, $invoiceCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'ZOMBIE_PAYMENT_REFUND',
                referenceCode: "ZOMBIE-{$invoiceCode}",
                description: "Pemulihan dana pembayaran terlambat (Zombie Payment) untuk tagihan {$invoiceCode}",
                orderId: null,
                paymentInvoiceId: $invoiceId,
            );
        });
    }

    /**
     * Credit buyer available_balance idempotently when a late payment arrives for an EXPIRED invoice.
     * Deliberately does NOT increase non_withdrawable_portion because these funds are a refund.
     */
    public function creditLatePaymentRefund(
        int $buyerId,
        int $invoiceId,
        float $amount,
        string $invoiceCode,
    ): WalletMutation {
        return DB::transaction(function () use ($buyerId, $invoiceId, $amount, $invoiceCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'LATE_PAYMENT_REFUND',
                referenceCode: "LATEPAY-{$invoiceCode}",
                description: "Pengembalian otomatis pembayaran terlambat (EXPIRED invoice) untuk tagihan {$invoiceCode}",
                orderId: null,
                paymentInvoiceId: $invoiceId,
            );
        });
    }

    public function holdForWithdrawal(int $userId, float $amount, string $withdrawCode): WalletMutation
    {
        return DB::transaction(function () use ($userId, $amount, $withdrawCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $userId,
                bucket: 'available',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: 'WITHDRAW_HOLD',
                referenceCode: "WD-HOLD-{$withdrawCode}",
                description: "Penahanan saldo di muka untuk penarikan {$withdrawCode}",
                orderId: null,
                paymentInvoiceId: null,
            );
        });
    }

    public function refundRejectedWithdrawal(int $userId, float $amount, string $withdrawCode): WalletMutation
    {
        return DB::transaction(function () use ($userId, $amount, $withdrawCode): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $userId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'WITHDRAW_REJECT_REFUND',
                referenceCode: "WD-REJ-{$withdrawCode}",
                description: "Pengembalian saldo penarikan ditolak {$withdrawCode}",
                orderId: null,
                paymentInvoiceId: null,
            );
        });
    }

    public function adminDemoTopup(
        int $userId,
        float $amount,
        string $referenceCode,
        string $description = 'Top-up saldo demo NexaTrade',
    ): WalletMutation {
        return DB::transaction(function () use ($userId, $amount, $referenceCode, $description): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $userId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'ADMIN_DEMO_TOPUP',
                referenceCode: $referenceCode,
                description: $description,
                orderId: null,
                paymentInvoiceId: null,
            );
        });
    }

    /**
     * Lock seller funds from available_balance into security_deposit_balance for Anti-Hackback guarantee.
     *
     * @return array{debit: WalletMutation, credit: WalletMutation}
     */
    public function lockSecurityDeposit(int $sellerId, float $amount, string $referenceSuffix): array
    {
        return DB::transaction(function () use ($sellerId, $amount, $referenceSuffix): array {
            $this->lockWalletsAscending([$sellerId]);

            $debit = $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'available',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: 'SECURITY_DEPOSIT_LOCK',
                referenceCode: "DEP-DEBIT-{$referenceSuffix}",
                description: 'Penguncian saldo aktif ke Deposit Jaminan Anti-Hackback',
                orderId: null,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            $credit = $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'security_deposit',
                direction: 'CREDIT',
                amount: $amount,
                mutationType: 'SECURITY_DEPOSIT_LOCK',
                referenceCode: "DEP-CREDIT-{$referenceSuffix}",
                description: 'Penambahan Deposit Jaminan Anti-Hackback toko',
                orderId: null,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            return ['debit' => $debit, 'credit' => $credit];
        });
    }

    /**
     * Execute post-settlement Hackback Warranty Clawback strictly preserving non-negative wallet balances.
     * Must be called inside an active transaction or standalone transaction.
     *
     * @return array{from_deposit: float, from_available: float, recorded_debt: float, buyer_refund: float}
     */
    public function executeWarrantyClawbackAndRefund(
        int $sellerId,
        int $buyerId,
        int $orderId,
        float $coverageAmount,
        string $claimCode,
    ): array {
        $coverageCents = $this->toCents($coverageAmount);
        if ($coverageCents <= 0) {
            throw new FinancialInvariantException('Nilai ganti rugi garansi harus lebih besar dari Rp 0.');
        }

        return DB::transaction(function () use ($sellerId, $buyerId, $orderId, $coverageCents, $claimCode): array {
            // Deterministic ascending wallet lock prevents deadlocks between buyer and seller.
            $this->lockWalletsAscending([$sellerId, $buyerId]);

            $sellerWallet = DB::table('wallets')->where('user_id', $sellerId)->first();
            if (! $sellerWallet) {
                throw new FinancialInvariantException("Wallet penjual #{$sellerId} tidak ditemukan.");
            }

            $depositCents = max(0, $this->toCents((float) $sellerWallet->security_deposit_balance));
            $availableCents = max(0, $this->toCents((float) $sellerWallet->available_balance));

            $fromDepositCents = min($depositCents, $coverageCents);
            $remainingAfterDeposit = $coverageCents - $fromDepositCents;
            $fromAvailableCents = min($availableCents, $remainingAfterDeposit);
            $debtCents = $coverageCents - $fromDepositCents - $fromAvailableCents;

            if ($fromDepositCents > 0) {
                $this->mutateLockedWallet(
                    userId: $sellerId,
                    bucket: 'security_deposit',
                    direction: 'DEBIT',
                    amount: $fromDepositCents / 100,
                    mutationType: 'WARRANTY_CLAWBACK_DEBIT',
                    referenceCode: "CLAW-DEP-{$claimCode}",
                    description: "Clawback Deposit Jaminan untuk klaim garansi {$claimCode}",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );
            }

            if ($fromAvailableCents > 0) {
                $this->mutateLockedWallet(
                    userId: $sellerId,
                    bucket: 'available',
                    direction: 'DEBIT',
                    amount: $fromAvailableCents / 100,
                    mutationType: 'WARRANTY_CLAWBACK_DEBIT',
                    referenceCode: "CLAW-AVL-{$claimCode}",
                    description: "Clawback Saldo Aktif untuk klaim garansi {$claimCode}",
                    orderId: $orderId,
                    paymentInvoiceId: null,
                    alreadyLocked: true,
                );
            }

            if ($debtCents > 0) {
                $currentDebtCents = max(0, $this->toCents((float) $sellerWallet->clawback_debt_balance));
                DB::table('wallets')
                    ->where('user_id', $sellerId)
                    ->update([
                        'clawback_debt_balance' => $this->fromCents($currentDebtCents + $debtCents),
                        'updated_at' => now(),
                    ]);
            }

            // Platform Guarantee Fund: buyer ALWAYS receives 100% of coverage_amount!
            $this->mutateLockedWallet(
                userId: $buyerId,
                bucket: 'available',
                direction: 'CREDIT',
                amount: $coverageCents / 100,
                mutationType: 'WARRANTY_BUYER_REFUND',
                referenceCode: "WAR-REF-{$claimCode}",
                description: "Ganti rugi 100% Garansi Anti-Hackback ({$claimCode})",
                orderId: $orderId,
                paymentInvoiceId: null,
                alreadyLocked: true,
            );

            return [
                'from_deposit' => round($fromDepositCents / 100, 2),
                'from_available' => round($fromAvailableCents / 100, 2),
                'recorded_debt' => round($debtCents / 100, 2),
                'buyer_refund' => round($coverageCents / 100, 2),
            ];
        });
    }

    public function debitForAdCreditPurchase(
        int $sellerId,
        float $amount,
        string $purchaseCode,
        string $packageName,
        bool $alreadyLocked = false,
    ): WalletMutation {
        return DB::transaction(function () use ($sellerId, $amount, $purchaseCode, $packageName, $alreadyLocked): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'available',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: 'AD_CREDIT_PURCHASE',
                referenceCode: "ADCRED-{$purchaseCode}",
                description: "Pembelian paket kredit promosi {$packageName} ({$purchaseCode})",
                orderId: null,
                paymentInvoiceId: null,
                alreadyLocked: $alreadyLocked,
            );
        });
    }

    public function debitForSellerDebtRecovery(
        int $sellerId,
        float $amount,
        string $repaymentCode,
        bool $alreadyLocked = false,
    ): WalletMutation {
        return DB::transaction(function () use ($sellerId, $amount, $repaymentCode, $alreadyLocked): WalletMutation {
            return $this->mutateLockedWallet(
                userId: $sellerId,
                bucket: 'available',
                direction: 'DEBIT',
                amount: $amount,
                mutationType: 'SELLER_DEBT_RECOVERY',
                referenceCode: "DEBT-REC-{$repaymentCode}",
                description: "Pelunasan tunggakan clawback garansi ({$repaymentCode})",
                orderId: null,
                paymentInvoiceId: null,
                alreadyLocked: $alreadyLocked,
            );
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    private function lockWalletsAscending(array $userIds): void
    {
        $sortedIds = array_values(array_unique($userIds));
        sort($sortedIds, SORT_NUMERIC);

        $locked = DB::table('wallets')
            ->whereIn('user_id', $sortedIds)
            ->orderBy('user_id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($locked->count() !== count($sortedIds)) {
            throw new FinancialInvariantException('Wallet record missing for one or more users during atomic lock.');
        }
    }

    private function mutateLockedWallet(
        int $userId,
        string $bucket,
        string $direction,
        float $amount,
        string $mutationType,
        string $referenceCode,
        string $description,
        ?int $orderId,
        ?int $paymentInvoiceId,
        bool $alreadyLocked = false,
    ): WalletMutation {
        $amountCents = $this->toCents($amount);

        if ($amountCents <= 0) {
            throw new FinancialInvariantException("Mutation amount must be strictly positive (got {$amount}).");
        }

        if (! in_array($bucket, ['pending', 'available', 'security_deposit'], true)) {
            throw new FinancialInvariantException("Invalid wallet bucket [{$bucket}].");
        }

        if (! in_array($direction, ['CREDIT', 'DEBIT'], true)) {
            throw new FinancialInvariantException("Invalid ledger direction [{$direction}].");
        }

        $walletQuery = DB::table('wallets')->where('user_id', $userId);
        $wallet = $alreadyLocked ? $walletQuery->first() : $walletQuery->lockForUpdate()->first();

        if (! $wallet) {
            throw new FinancialInvariantException("Wallet not found for user_id={$userId}.");
        }

        // Strict idempotency check inside the row-locked transaction.
        $existing = WalletMutation::where('reference_code', $referenceCode)->first();
        if ($existing) {
            return $existing;
        }

        $column = match ($bucket) {
            'pending' => 'pending_balance',
            'security_deposit' => 'security_deposit_balance',
            default => 'available_balance',
        };
        $beforeCents = $this->toCents((float) $wallet->{$column});

        if ($direction === 'DEBIT') {
            if ($beforeCents < $amountCents) {
                $formattedBefore = number_format($beforeCents / 100, 2, '.', '');
                $formattedAmount = number_format($amountCents / 100, 2, '.', '');
                throw new InsufficientWalletBalanceException(
                    "Saldo {$bucket} tidak mencukupi (tersedia Rp {$formattedBefore}, dibutuhkan Rp {$formattedAmount})."
                );
            }
            $afterCents = $beforeCents - $amountCents;
            if ($afterCents !== ($beforeCents - $amountCents)) {
                throw new FinancialInvariantException('Debit ledger math invariant violated.');
            }
        } else {
            $afterCents = $beforeCents + $amountCents;
            if ($afterCents !== ($beforeCents + $amountCents)) {
                throw new FinancialInvariantException('Credit ledger math invariant violated.');
            }
        }

        if ($afterCents < 0) {
            throw new FinancialInvariantException("Negative wallet balance invariant violation on user_id={$userId}.");
        }

        foreach (['pending_balance', 'available_balance', 'security_deposit_balance'] as $checkCol) {
            if (property_exists($wallet, $checkCol) && $this->toCents((float) $wallet->{$checkCol}) < 0) {
                throw new FinancialInvariantException("Corrupted negative {$checkCol} detected on user_id={$userId}.");
            }
        }

        $beforeDecimal = $this->fromCents($beforeCents);
        $amountDecimal = $this->fromCents($amountCents);
        $afterDecimal = $this->fromCents($afterCents);

        DB::table('wallets')
            ->where('user_id', $userId)
            ->update([
                $column => $afterDecimal,
                'updated_at' => now(),
            ]);

        return WalletMutation::create([
            'reference_code' => $referenceCode,
            'user_id' => $userId,
            'order_id' => $orderId,
            'payment_invoice_id' => $paymentInvoiceId,
            'balance_bucket' => $bucket,
            'direction' => $direction,
            'amount' => $amountDecimal,
            'balance_before' => $beforeDecimal,
            'balance_after' => $afterDecimal,
            'mutation_type' => $mutationType,
            'description' => mb_substr($description, 0, 255),
            'created_at' => now(),
        ]);
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
