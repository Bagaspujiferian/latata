<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\StateTransitionGuard;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ManualOrderCancellationService
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
        private readonly ChatModerationService $chatModerationService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Mark a manual trade order as accepted/in-progress by the seller, locking unilateral buyer cancellation.
     */
    public function sellerAcceptManualOrder(int $sellerId, int $orderId): Order
    {
        return DB::transaction(function () use ($sellerId, $orderId): Order {
            $order = Order::with(['items', 'paymentInvoice'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $order->seller_id !== $sellerId) {
                throw new AuthorizationException('Anda tidak berwenang memproses pesanan milik toko lain.');
            }

            if ($order->delivery_method !== 'manual_trade') {
                throw new DomainException('Konfirmasi terima pesanan hanya berlaku untuk produk bertipe Manual Trade.');
            }

            if (! in_array($order->status, ['PAID_ESCROW', 'PROCESSING'], true)) {
                throw new DomainException("Pesanan dengan status [{$order->status}] tidak dapat ditandai mulai diproses.");
            }

            if ($order->seller_accepted_at !== null) {
                throw new DomainException('Pesanan ini sudah diterima dan sedang dalam tahap pengerjaan.');
            }

            $now = now();
            $nextStatus = $order->status === 'PAID_ESCROW' ? 'PROCESSING' : $order->status;

            $order->update([
                'status' => $nextStatus,
                'seller_accepted_at' => $now,
            ]);

            $seller = User::findOrFail($sellerId);
            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $seller,
                rawMessage: '[PENJUAL MULAI MEMPROSES] Penjual telah menerima dan mulai mengerjakan pesanan manual ini. Pembatalan sepihak oleh pembeli kini dikunci hingga batas SLA berakhir.',
                senderRoleOverride: 'system',
            );

            $this->notificationService->notifyAfterCommit(
                userId: (int) $order->buyer_id,
                category: 'order',
                title: "Pesanan {$order->order_code} Sedang Diproses Penjual",
                body: 'Penjual telah mulai mengerjakan pesanan manual Anda. Mohon tunggu bukti pengiriman sebelum batas SLA.',
                actionUrl: "/buyer/orders/{$order->order_code}",
                refType: 'order',
                refId: (int) $order->id,
            );

            return $order->refresh()->load(['items', 'paymentInvoice']);
        });
    }

    /**
     * Allow buyer to cancel a paid manual trade order BEFORE the seller has accepted/started processing it.
     * Refunds exact buyer_payable_amount (Anti-Farming Voucher law) and debits seller pending_balance by seller_escrow_amount.
     */
    public function buyerCancelUnprocessedOrder(int $buyerId, int $orderId, string $reason): Order
    {
        $cleanReason = mb_substr(trim($reason), 0, 255);
        if ($cleanReason === '') {
            throw new DomainException('Alasan pembatalan pesanan wajib diisi.');
        }

        return DB::transaction(function () use ($buyerId, $orderId, $cleanReason): Order {
            $order = Order::with(['items', 'paymentInvoice'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new DomainException("Pesanan #{$orderId} tidak ditemukan.");
            }

            if ((int) $order->buyer_id !== $buyerId) {
                throw new AuthorizationException('Hanya pembeli pemilik pesanan yang dapat membatalkan pesanan ini.');
            }

            if ($order->delivery_method !== 'manual_trade') {
                throw new DomainException('Pembatalan sepihak sebelum diproses hanya berlaku untuk pesanan Manual Trade.');
            }

            if (! in_array($order->status, ['PAID_ESCROW', 'PROCESSING'], true)) {
                throw new DomainException("Pesanan dengan status [{$order->status}] tidak dapat dibatalkan.");
            }

            if ($order->seller_accepted_at !== null) {
                throw new DomainException('Pesanan ini sudah diterima dan mulai diproses oleh penjual sehingga tidak dapat dibatalkan sepihak.');
            }

            StateTransitionGuard::assertOrderTransition(
                (string) $order->status,
                'REFUNDED',
                "Buyer Pre-Processing Cancel {$order->order_code}",
            );

            // Restore manual trade product stock with deterministic ascending product lock
            $productIds = $order->items
                ->pluck('product_id')
                ->unique()
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($productIds !== []) {
                DB::table('products')->whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get();
                foreach ($order->items as $item) {
                    DB::table('products')->where('id', $item->product_id)->increment('stock', (int) $item->quantity);
                }
            }

            $order->update([
                'status' => 'REFUNDED',
                'cancelled_by_role' => 'buyer',
                'cancellation_reason' => $cleanReason,
                'cancelled_reason' => mb_substr("Dibatalkan Pembeli (Pre-Processing): {$cleanReason}", 0, 255),
            ]);

            $buyerRefundAmount = $order->refundableBuyerAmount();

            $this->ledgerService->refundEscrowToBuyer(
                sellerId: (int) $order->seller_id,
                buyerId: (int) $order->buyer_id,
                orderId: (int) $order->id,
                sellerEscrowAmount: (float) $order->seller_escrow_amount,
                buyerRefundAmount: $buyerRefundAmount,
                orderCode: $order->order_code,
                reason: "Pembatalan Pembeli Sebelum Diproses ({$cleanReason})",
            );

            $buyer = User::findOrFail($buyerId);
            $this->chatModerationService->postOrderMessage(
                order: $order,
                sender: $buyer,
                rawMessage: "[DIBATALKAN PEMBELI • PRE-PROCESSING] Pembeli membatalkan pesanan sebelum diproses penjual (Alasan: {$cleanReason}). Dana bersih Rp ".number_format($buyerRefundAmount, 0, ',', '.').' telah dikembalikan ke Saldo Aktif Pembeli.',
                senderRoleOverride: 'system',
            );

            $this->notificationService->notifyAfterCommit(
                userId: (int) $order->seller_id,
                category: 'escrow',
                title: "Pesanan {$order->order_code} Dibatalkan Pembeli",
                body: "Pembeli membatalkan pesanan sebelum mulai diproses (Alasan: {$cleanReason}). Stok produk telah dikembalikan.",
                actionUrl: '/seller/orders',
                refType: 'order',
                refId: (int) $order->id,
            );

            return $order->refresh()->load(['items', 'paymentInvoice']);
        });
    }
}
