<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Order;
use App\Models\OrderReview;
use App\Models\PaymentInvoice;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\WithdrawalRequest;
use App\Support\StateTransitionGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderStateAndSlaService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly VaultEncryptionService $vaultService,
        private readonly SellerSlaMetricsService $slaMetricsService,
        private readonly ChatModerationService $chatModerationService,
        private readonly NotificationService $notificationService,
        private readonly AccountWarrantyService $warrantyService,
        private readonly VoucherAccountingService $voucherService,
        private readonly FlashSaleService $flashSaleService,
    ) {}

    public function sellerSubmitManualDelivery(
        int $orderId,
        int $sellerId,
        string $deliveryNotes,
        ?string $proofUrl = null,
    ): Order {
        return DB::transaction(function () use ($orderId, $sellerId, $deliveryNotes, $proofUrl): Order {
            $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $orderRow) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $orderRow->seller_id !== $sellerId) {
                throw new DomainException('Anda tidak memiliki izin mengirim pesanan milik toko lain.');
            }

            StateTransitionGuard::assertOrderTransition($orderRow->status, 'DELIVERED', "Manual Delivery {$orderRow->order_code}");

            $now = now();
            $holdHours = (int) (DB::table('order_items')
                ->join('products', 'products.id', '=', 'order_items.product_id')
                ->join('game_categories', 'game_categories.id', '=', 'products.game_category_id')
                ->where('order_items.order_id', $orderId)
                ->max('game_categories.escrow_hold_hours') ?: config('nexatrade.auto_complete_hours', 24));
            $autoCompleteAt = $now->copy()->addHours(max(1, $holdHours));
            $proof = [
                'type' => 'manual_trade_proof',
                'notes' => trim($deliveryNotes),
                'proof_url' => $proofUrl ? trim($proofUrl) : null,
                'submitted_by' => $sellerId,
                'delivered_at' => $now->toIso8601String(),
            ];

            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'DELIVERED',
                'seller_delivery_proof' => json_encode($proof),
                'delivered_at' => $now,
                'auto_complete_at' => $autoCompleteAt,
                'updated_at' => $now,
            ]);

            $this->slaMetricsService->recalculateSellerSla($sellerId);

            $order = Order::with(['items.product.gameCategory', 'paymentInvoice'])->findOrFail($orderId);
            $this->warrantyService->issueWarrantyIfEligible($order);
            $seller = User::findOrFail($sellerId);

            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $seller,
                rawMessage: "[BUKTI KIRIM PENJUAL] {$deliveryNotes}".($proofUrl ? " (Bukti: {$proofUrl})" : '').'. Pembeli memiliki waktu 24 jam untuk memeriksa sebelum dana cair otomatis.',
                attachmentProofUrl: $proofUrl,
                senderRoleOverride: 'system',
            );

            $this->notificationService->notify(
                userId: (int) $order->buyer_id,
                category: 'order',
                title: "Pesanan {$order->order_code} Telah Dikirim Penjual",
                body: 'Periksa item pesanan Anda dan klik Konfirmasi Selesai dalam 24 jam.',
                actionUrl: "/buyer/orders/{$order->order_code}",
                refType: 'order',
                refId: (int) $order->id,
            );

            return $order;
        });
    }

    public function buyerClaimSlaBreachRefund(int $orderId, int $buyerId): Order
    {
        return DB::transaction(function () use ($orderId, $buyerId): Order {
            $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $orderRow) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $orderRow->buyer_id !== $buyerId) {
                throw new DomainException('Hanya pembeli pemilik pesanan yang dapat mengklaim refund SLA.');
            }

            StateTransitionGuard::assertOrderTransition($orderRow->status, 'REFUNDED', "SLA Breach {$orderRow->order_code}");

            if ($orderRow->sla_deadline_at === null || ! now()->greaterThan($orderRow->sla_deadline_at)) {
                throw new DomainException('Batas waktu SLA penjual belum terlewati. Refund instan terbuka otomatis tepat setelah timer SLA habis.');
            }

            $order = Order::with('items')->findOrFail($orderId);

            // Restore non-vault product stock using deterministic ascending product lock.
            if ($order->delivery_method !== 'instant_vault') {
                $productIds = $order->items->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->sort()->values()->all();
                if (! empty($productIds)) {
                    DB::table('products')->whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get();
                    foreach ($order->items as $item) {
                        DB::table('products')->where('id', $item->product_id)->increment('stock', (int) $item->quantity);
                    }
                }
            }

            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'REFUNDED',
                'cancelled_reason' => 'SLA Breach: penjual melewati batas waktu pengiriman '.$orderRow->sla_target_minutes_snapshot.' menit.',
                'updated_at' => now(),
            ]);

            DB::table('seller_profiles')
                ->where('user_id', $order->seller_id)
                ->lockForUpdate()
                ->first();

            DB::table('seller_profiles')
                ->where('user_id', $order->seller_id)
                ->increment('total_cancelled_orders');

            $this->slaMetricsService->recalculateSellerSla((int) $order->seller_id);

            $buyerRefundAmount = $order->refundableBuyerAmount();
            $this->ledgerService->refundEscrowToBuyer(
                sellerId: (int) $order->seller_id,
                buyerId: (int) $order->buyer_id,
                orderId: (int) $order->id,
                sellerEscrowAmount: (float) $order->seller_escrow_amount,
                buyerRefundAmount: $buyerRefundAmount,
                orderCode: $order->order_code,
                reason: 'Klaim Refund Otomatis Pelanggaran SLA',
            );

            $buyer = User::findOrFail($buyerId);
            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $buyer,
                rawMessage: '[REFUND SLA OTOMATIS] Penjual melewati target SLA. Pesanan dibatalkan dan dana Rp '.number_format($buyerRefundAmount, 0, ',', '.').' langsung masuk ke Saldo Aktif Pembeli.',
                senderRoleOverride: 'system',
            );

            return $order->fresh(['items', 'paymentInvoice']);
        });
    }

    public function buyerConfirmOrderCompleted(
        int $orderId,
        int $buyerId,
        ?int $rating = null,
        ?string $comment = null,
    ): Order {
        return DB::transaction(function () use ($orderId, $buyerId, $rating, $comment): Order {
            $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $orderRow) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $orderRow->buyer_id !== $buyerId) {
                throw new DomainException('Hanya pembeli pemilik pesanan yang dapat mengonfirmasi selesai.');
            }

            if (! in_array($orderRow->status, ['DELIVERED', 'DISPUTED'], true)) {
                StateTransitionGuard::assertOrderTransition($orderRow->status, 'COMPLETED', "Buyer Confirm {$orderRow->order_code}");
            }

            StateTransitionGuard::assertOrderTransition($orderRow->status, 'COMPLETED', "Buyer Confirm {$orderRow->order_code}");

            $order = $this->finalizeCompletedOrderWithinLock($orderRow, 'Pembeli mengonfirmasi pesanan selesai.');

            if ($rating !== null && $rating >= 1 && $rating <= 5) {
                $firstItem = $order->items->first();
                if ($firstItem && ! OrderReview::where('order_id', $order->id)->exists()) {
                    $start = $order->paymentInvoice?->paid_at ?? $order->created_at;
                    $end = $order->delivered_at ?? now();
                    $durationMinutes = max(1, (int) round($start->diffInMinutes($end)));

                    OrderReview::create([
                        'order_id' => $order->id,
                        'product_id' => $firstItem->product_id,
                        'seller_id' => $order->seller_id,
                        'buyer_id' => $buyerId,
                        'rating' => $rating,
                        'comment' => $comment ? mb_substr(trim($comment), 0, 500) : null,
                        'delivery_duration_minutes' => $durationMinutes,
                    ]);

                    $avgRating = OrderReview::where('product_id', $firstItem->product_id)->avg('rating');
                    if ($avgRating !== null) {
                        DB::table('products')->where('id', $firstItem->product_id)->update([
                            'rating_avg' => number_format((float) $avgRating, 2, '.', ''),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            return $order->fresh(['items', 'review', 'paymentInvoice']);
        });
    }

    public function buyerOpenDispute(int $orderId, int $buyerId, string $reason, ?string $proofUrl = null): Order
    {
        return DB::transaction(function () use ($orderId, $buyerId, $reason, $proofUrl): Order {
            $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $orderRow) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $orderRow->buyer_id !== $buyerId) {
                throw new DomainException('Hanya pembeli pemilik pesanan yang dapat mengajukan komplain.');
            }

            // Atomic State Guard prevents Dispute vs Auto-Complete race condition.
            StateTransitionGuard::assertOrderTransition($orderRow->status, 'DISPUTED', "Open Dispute {$orderRow->order_code}");

            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'DISPUTED',
                'auto_complete_at' => null,
                'updated_at' => now(),
            ]);

            $order = Order::with(['items', 'paymentInvoice'])->findOrFail($orderId);
            $buyer = User::findOrFail($buyerId);

            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $buyer,
                rawMessage: $reason,
                attachmentProofUrl: $proofUrl,
                senderRoleOverride: 'buyer',
            );

            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $buyer,
                rawMessage: '[ESCROW DIBEKUKAN • SENGKETA AKTIF] Timer pencairan otomatis 24 jam dihentikan. Tim Arbitrase Admin NexaTrade kini dapat memeriksa bukti kedua belah pihak.',
                senderRoleOverride: 'system',
            );

            $this->notificationService->notify(
                userId: (int) $order->seller_id,
                category: 'escrow',
                title: "Komplain Dibuka untuk {$order->order_code}",
                body: 'Escrow dibekukan sementara menunggu tinjauan Tim Arbitrase Admin NexaTrade.',
                actionUrl: '/seller/orders',
                refType: 'order',
                refId: (int) $order->id,
            );

            $this->notificationService->notifyAdmins(
                category: 'escrow',
                title: "Sengketa Baru: {$order->order_code}",
                body: "Pembeli membuka komplain pada pesanan {$order->order_code}. Segera tinjau di Meja Sengketa.",
                actionUrl: '/admin/disputes',
                refType: 'order',
                refId: (int) $order->id,
            );

            return $order->fresh(['items', 'messages']);
        });
    }

    public function adminResolveDispute(
        int $orderId,
        int $adminId,
        string $verdict,
        string $adminNotes,
    ): Order {
        if (! in_array($verdict, ['RELEASE_TO_SELLER', 'REFUND_TO_BUYER'], true)) {
            throw new DomainException("Putusan arbitrase tidak valid: [{$verdict}].");
        }

        return DB::transaction(function () use ($orderId, $adminId, $verdict, $adminNotes): Order {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin Arbitrase yang dapat memutus sengketa.');
            }

            $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if (! $orderRow) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ($verdict === 'RELEASE_TO_SELLER') {
                StateTransitionGuard::assertOrderTransition($orderRow->status, 'COMPLETED', "Admin Verdict {$orderRow->order_code}");
                $order = $this->finalizeCompletedOrderWithinLock($orderRow, "Putusan Admin Arbitrase: Menangkan Penjual. {$adminNotes}");

                $this->chatModerationService->postOrderMessage(
                    order: $order,
                    sender: $admin,
                    rawMessage: "[PUTUSAN ARBITRASE: MENANGKAN PENJUAL] {$adminNotes}. Dana escrow Rp ".number_format((float) $order->seller_escrow_amount, 0, ',', '.').' dicairkan ke Saldo Aktif Penjual.',
                    senderRoleOverride: 'admin',
                );

                return $order->fresh(['items', 'messages']);
            }

            StateTransitionGuard::assertOrderTransition($orderRow->status, 'REFUNDED', "Admin Verdict {$orderRow->order_code}");

            DB::table('orders')->where('id', $orderId)->update([
                'status' => 'REFUNDED',
                'cancelled_reason' => mb_substr("Putusan Arbitrase Admin (Menangkan Pembeli): {$adminNotes}", 0, 255),
                'updated_at' => now(),
            ]);

            DB::table('seller_profiles')->where('user_id', $orderRow->seller_id)->lockForUpdate()->first();
            DB::table('seller_profiles')->where('user_id', $orderRow->seller_id)->increment('total_cancelled_orders');
            $this->slaMetricsService->recalculateSellerSla((int) $orderRow->seller_id);

            $order = Order::with(['items', 'messages'])->findOrFail($orderId);
            $buyerRefundAmount = $order->refundableBuyerAmount();

            $this->ledgerService->refundEscrowToBuyer(
                sellerId: (int) $orderRow->seller_id,
                buyerId: (int) $orderRow->buyer_id,
                orderId: (int) $orderRow->id,
                sellerEscrowAmount: (float) $orderRow->seller_escrow_amount,
                buyerRefundAmount: $buyerRefundAmount,
                orderCode: $orderRow->order_code,
                reason: "Putusan Arbitrase Admin: {$adminNotes}",
            );

            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $admin,
                rawMessage: "[PUTUSAN ARBITRASE: MENANGKAN PEMBELI] {$adminNotes}. Dana Rp ".number_format($buyerRefundAmount, 0, ',', '.').' dikembalikan ke Saldo Aktif Pembeli.',
                senderRoleOverride: 'admin',
            );

            return $order->fresh(['items', 'messages']);
        });
    }

    /**
     * Sweep all UNPAID invoices past expires_at: cancel child orders, un-reserve vaults,
     * resync stock, and automatically refund HYBRID_WALLET_HOLD balances!
     *
     * @return array{expired_count: int, invoices: list<array<string, mixed>>}
     */
    public function sweepExpiredInvoices(): array
    {
        $candidateIds = DB::table('payment_invoices')
            ->where('status', 'UNPAID')
            ->where('expires_at', '<=', now())
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->all();

        $results = [];
        foreach ($candidateIds as $invoiceId) {
            $summary = DB::transaction(function () use ($invoiceId): ?array {
                $invoiceRow = DB::table('payment_invoices')
                    ->where('id', $invoiceId)
                    ->lockForUpdate()
                    ->first();

                if (! $invoiceRow || $invoiceRow->status !== 'UNPAID') {
                    return null;
                }

                $invoiceModel = PaymentInvoice::findOrFail($invoiceId);

                return $this->expireInvoiceWithinTransaction($invoiceModel, 'Tagihan melewati batas waktu pembayaran (Sweeper Worker).');
            });

            if ($summary !== null) {
                $results[] = $summary;
            }
        }

        return [
            'expired_count' => count($results),
            'invoices' => $results,
        ];
    }

    /**
     * Must be called inside an active transaction with payment_invoices row already locked.
     *
     * @return array<string, mixed>
     */
    public function expireInvoiceWithinTransaction(PaymentInvoice $invoice, string $reason): array
    {
        StateTransitionGuard::assertInvoiceTransition($invoice->status, 'EXPIRED', "Expire {$invoice->invoice_code}");

        DB::table('payment_invoices')
            ->where('id', $invoice->id)
            ->update([
                'status' => 'EXPIRED',
                'updated_at' => now(),
            ]);

        $childOrders = Order::with('items')
            ->where('payment_invoice_id', $invoice->id)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $allProductIds = [];
        foreach ($childOrders as $order) {
            foreach ($order->items as $item) {
                $allProductIds[] = (int) $item->product_id;
            }
        }

        // Deterministic ascending product lock before stock restoration / vault resync.
        $allProductIds = array_values(array_unique($allProductIds));
        sort($allProductIds, SORT_NUMERIC);
        if (! empty($allProductIds)) {
            DB::table('products')->whereIn('id', $allProductIds)->orderBy('id', 'asc')->lockForUpdate()->get();
        }

        $releasedVaultCount = 0;
        $releasedFlashQty = 0;
        $vaultProductsToSync = [];
        $cancelledOrders = [];

        foreach ($childOrders as $order) {
            StateTransitionGuard::assertOrderTransition($order->status, 'CANCELLED', "Expire {$order->order_code}");

            DB::table('orders')
                ->where('id', $order->id)
                ->update([
                    'status' => 'CANCELLED',
                    'cancelled_reason' => mb_substr($reason, 0, 255),
                    'updated_at' => now(),
                ]);

            $releasedFlashQty += $this->flashSaleService->releaseFlashQuotaForCancelledOrder((int) $order->id);

            if ($order->delivery_method === 'instant_vault') {
                $released = DB::table('product_vaults')
                    ->where('reserved_order_id', $order->id)
                    ->where('status', 'reserved')
                    ->update([
                        'status' => 'available',
                        'reserved_order_id' => null,
                        'reserved_until' => null,
                        'updated_at' => now(),
                    ]);

                $releasedVaultCount += $released;

                foreach ($order->items as $item) {
                    $vaultProductsToSync[(int) $item->product_id] = true;
                }
            } else {
                foreach ($order->items as $item) {
                    DB::table('products')
                        ->where('id', $item->product_id)
                        ->increment('stock', (int) $item->quantity);
                }
            }

            $cancelledOrders[] = $order->order_code;
        }

        foreach (array_keys($vaultProductsToSync) as $vaultProductId) {
            $this->vaultService->syncVaultStockWithinLock((int) $vaultProductId);
        }

        // Release reserved voucher quotas & usages when unpaid invoice expires.
        $releasedVoucherCount = $this->voucherService->releaseVouchersForExpiredInvoice((int) $invoice->id);

        // HYBRID PAYMENT ROLLBACK LAW (#6): refund wallet_used_amount automatically!
        $hybridRefundRef = null;
        $hybridRefundedAmount = 0.00;
        if ((float) $invoice->wallet_used_amount > 0) {
            $hybridRefundedAmount = (float) $invoice->wallet_used_amount;
            $mutation = $this->ledgerService->refundExpiredHybridWalletHold(
                buyerId: (int) $invoice->buyer_id,
                invoiceId: (int) $invoice->id,
                amount: $hybridRefundedAmount,
                invoiceCode: $invoice->invoice_code,
            );
            $hybridRefundRef = $mutation->reference_code;
        }

        return [
            'invoice_code' => $invoice->invoice_code,
            'new_status' => 'EXPIRED',
            'cancelled_orders' => $cancelledOrders,
            'released_vault_count' => $releasedVaultCount,
            'released_flash_qty' => $releasedFlashQty,
            'released_voucher_count' => $releasedVoucherCount,
            'hybrid_refund_amount' => $hybridRefundedAmount,
            'hybrid_refund_ref' => $hybridRefundRef,
        ];
    }

    /**
     * Auto-complete all DELIVERED orders past auto_complete_at and release escrow to seller available_balance.
     *
     * @return array{settled_count: int, orders: list<array<string, mixed>>}
     */
    public function settleCompletedOrders(): array
    {
        $candidateIds = DB::table('orders')
            ->where('status', 'DELIVERED')
            ->whereNotNull('auto_complete_at')
            ->where('auto_complete_at', '<=', now())
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->all();

        $settled = [];
        foreach ($candidateIds as $orderId) {
            $result = DB::transaction(function () use ($orderId): ?array {
                $orderRow = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();

                // Atomic State Guard: if buyer opened a dispute milliseconds ago, status is DISPUTED -> skip!
                if (! $orderRow || $orderRow->status !== 'DELIVERED' || $orderRow->auto_complete_at === null) {
                    return null;
                }

                if (! now()->greaterThanOrEqualTo($orderRow->auto_complete_at)) {
                    return null;
                }

                StateTransitionGuard::assertOrderTransition($orderRow->status, 'COMPLETED', "Auto-Settle {$orderRow->order_code}");
                $completed = $this->finalizeCompletedOrderWithinLock($orderRow, 'Pencairan otomatis setelah melewati masa garansi 24 jam.');

                return [
                    'order_code' => $completed->order_code,
                    'seller_id' => (int) $completed->seller_id,
                    'released_escrow_amount' => (float) $completed->seller_escrow_amount,
                    'debit_ref' => "REL-DEBIT-{$completed->order_code}",
                    'credit_ref' => "REL-CREDIT-{$completed->order_code}",
                ];
            });

            if ($result !== null) {
                $settled[] = $result;
            }
        }

        return [
            'settled_count' => count($settled),
            'orders' => $settled,
        ];
    }

    public function requestWithdrawal(
        int $userId,
        string $bankName,
        string $accountNumber,
        string $accountHolder,
        float $amount,
        ?int $userBankAccountId = null,
    ): WithdrawalRequest {
        $adminFee = (float) config('nexatrade.withdrawal_admin_fee', 2500.00);
        $minAmount = (float) config('nexatrade.withdrawal_min_amount', 10000.00);

        if ($amount < $minAmount) {
            throw new DomainException('Minimal penarikan saldo adalah Rp '.number_format($minAmount, 0, ',', '.').'.');
        }

        if ($amount <= $adminFee) {
            throw new DomainException('Nominal penarikan harus lebih besar dari biaya admin bank.');
        }

        return DB::transaction(function () use ($userId, $bankName, $accountNumber, $accountHolder, $amount, $adminFee, $userBankAccountId): WithdrawalRequest {
            $snapshotBankName = strtoupper(trim($bankName));
            $snapshotAccountNumber = trim($accountNumber);
            $snapshotAccountHolder = trim($accountHolder);
            $resolvedBankAccountId = null;

            if ($userBankAccountId !== null) {
                $savedAccount = UserBankAccount::where('id', $userBankAccountId)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (! $savedAccount) {
                    throw new DomainException('Rekening tujuan tidak ditemukan atau sudah dihapus.');
                }

                if (! $savedAccount->is_verified) {
                    throw new DomainException('Rekening tujuan belum terverifikasi.');
                }

                $resolvedBankAccountId = $savedAccount->id;
                $snapshotBankName = $savedAccount->bank_name;
                $snapshotAccountNumber = $savedAccount->account_number;
                $snapshotAccountHolder = $savedAccount->account_holder_name;
            }

            $withdrawCode = 'WD-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            $netPayout = round($amount - $adminFee, 2);

            // Upfront Debit & Row Lock (Anti Double-Withdrawal Loophole #10)
            $this->ledgerService->holdForWithdrawal($userId, $amount, $withdrawCode);

            return WithdrawalRequest::create([
                'withdraw_code' => $withdrawCode,
                'user_id' => $userId,
                'user_bank_account_id' => $resolvedBankAccountId,
                'bank_name' => $snapshotBankName,
                'account_number' => $snapshotAccountNumber,
                'account_holder' => $snapshotAccountHolder,
                'amount' => number_format($amount, 2, '.', ''),
                'admin_fee' => number_format($adminFee, 2, '.', ''),
                'net_payout' => number_format($netPayout, 2, '.', ''),
                'status' => 'PENDING',
            ]);
        });
    }

    public function approveWithdrawal(int $withdrawalId, int $adminId): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalId, $adminId): WithdrawalRequest {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin yang dapat menyetujui penarikan saldo.');
            }

            $row = DB::table('withdrawal_requests')->where('id', $withdrawalId)->lockForUpdate()->first();
            if (! $row) {
                throw new DomainException("Permintaan penarikan #{$withdrawalId} tidak ditemukan.");
            }

            if ($row->status !== 'PENDING') {
                throw new DomainException("Penarikan {$row->withdraw_code} sudah diproses sebelumnya (status: {$row->status}).");
            }

            DB::table('withdrawal_requests')->where('id', $withdrawalId)->update([
                'status' => 'APPROVED_PAID',
                'processed_by' => $adminId,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            $withdrawal = WithdrawalRequest::findOrFail($withdrawalId);

            $this->notificationService->notify(
                userId: (int) $withdrawal->user_id,
                category: 'withdrawal',
                title: "Penarikan {$withdrawal->withdraw_code} Disetujui",
                body: 'Dana bersih Rp '.number_format((float) $withdrawal->net_payout, 0, ',', '.')." telah ditransfer ke {$withdrawal->bank_name} ({$withdrawal->account_number}).",
                actionUrl: '/seller/wallet',
                refType: 'withdrawal',
                refId: (int) $withdrawal->id,
            );

            return $withdrawal;
        });
    }

    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): WithdrawalRequest
    {
        return DB::transaction(function () use ($withdrawalId, $adminId, $reason): WithdrawalRequest {
            $admin = User::findOrFail($adminId);
            if (! $admin->isAdmin()) {
                throw new DomainException('Hanya Admin yang dapat menolak penarikan saldo.');
            }

            $row = DB::table('withdrawal_requests')->where('id', $withdrawalId)->lockForUpdate()->first();
            if (! $row) {
                throw new DomainException("Permintaan penarikan #{$withdrawalId} tidak ditemukan.");
            }

            if ($row->status !== 'PENDING') {
                throw new DomainException("Penarikan {$row->withdraw_code} sudah diproses sebelumnya (status: {$row->status}).");
            }

            DB::table('withdrawal_requests')->where('id', $withdrawalId)->update([
                'status' => 'REJECTED_REFUNDED',
                'processed_by' => $adminId,
                'rejection_reason' => mb_substr(trim($reason), 0, 255),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            $this->ledgerService->refundRejectedWithdrawal(
                userId: (int) $row->user_id,
                amount: (float) $row->amount,
                withdrawCode: $row->withdraw_code,
            );

            $withdrawal = WithdrawalRequest::findOrFail($withdrawalId);

            $this->notificationService->notify(
                userId: (int) $withdrawal->user_id,
                category: 'withdrawal',
                title: "Penarikan {$withdrawal->withdraw_code} Ditolak & Dikembalikan",
                body: "Alasan: {$withdrawal->rejection_reason}. Saldo Rp ".number_format((float) $withdrawal->amount, 0, ',', '.').' dikembalikan ke Saldo Aktif.',
                actionUrl: '/seller/wallet',
                refType: 'withdrawal',
                refId: (int) $withdrawal->id,
            );

            return $withdrawal;
        });
    }

    private function finalizeCompletedOrderWithinLock(object $orderRow, string $note): Order
    {
        $now = now();

        DB::table('orders')->where('id', $orderRow->id)->update([
            'status' => 'COMPLETED',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('seller_profiles')->where('user_id', $orderRow->seller_id)->lockForUpdate()->first();
        DB::table('seller_profiles')->where('user_id', $orderRow->seller_id)->increment('total_completed_orders');

        $order = Order::with(['items', 'paymentInvoice'])->findOrFail($orderRow->id);

        // For non-vault orders, increment sold_count upon final COMPLETED confirmation.
        if ($order->delivery_method !== 'instant_vault') {
            $productIds = $order->items->pluck('product_id')->unique()->map(fn ($id) => (int) $id)->sort()->values()->all();
            if (! empty($productIds)) {
                DB::table('products')->whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get();
                foreach ($order->items as $item) {
                    DB::table('products')->where('id', $item->product_id)->increment('sold_count', (int) $item->quantity);
                }
            }
        }

        $this->ledgerService->releaseEscrowToAvailable(
            sellerId: (int) $order->seller_id,
            orderId: (int) $order->id,
            amount: (float) $order->seller_escrow_amount,
            orderCode: $order->order_code,
        );

        $this->slaMetricsService->recalculateSellerSla((int) $order->seller_id);
        $this->warrantyService->issueWarrantyIfEligible($order);

        $seller = User::findOrFail((int) $order->seller_id);
        $this->chatModerationService->postOrderMessage(
            order: $order,
            sender: $seller,
            rawMessage: "[PESANAN SELESAI • ESCROW CAIR] {$note} Dana bersih Rp ".number_format((float) $order->seller_escrow_amount, 0, ',', '.').' telah dicairkan ke Saldo Aktif Penjual.',
            senderRoleOverride: 'system',
        );

        $this->notificationService->notify(
            userId: (int) $order->seller_id,
            category: 'escrow',
            title: "Escrow Cair: {$order->order_code}",
            body: 'Dana bersih Rp '.number_format((float) $order->seller_escrow_amount, 0, ',', '.').' telah dicairkan ke Saldo Aktif Anda.',
            actionUrl: '/seller/wallet',
            refType: 'order',
            refId: (int) $order->id,
        );

        return $order;
    }
}
